<?php
/**
 * REST controller for customer requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Actions\RequestLifecycle;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\Sanitizer;
use PostPurchaseHub\Support\Logger;

/**
 * `POST /wpmphub/v1/requests` and `DELETE /wpmphub/v1/requests/{id}`.
 *
 * Every mutation passes through the same order: rate limit by IP (cheapest,
 * no order needed) → `OwnershipResolver::assertCanAccess()` (loads and
 * authorises the order; a guest's token arrives as a request param, wired
 * through `wpmphub_current_request_token` for the life of this request only) →
 * rate limit by the order's own billing email → the action's own `check()`
 * re-run at execution time, never trusted from anything the client sent.
 *
 * A logged-in customer's nonce is verified by WordPress core itself before
 * any of this runs — `rest_cookie_check_errors()` rejects a missing or stale
 * `X-WP-Nonce` for any authenticated REST request, on every route, with no
 * code required here. A guest carries no cookie, so that check is inert for
 * them and the signed token is the entire authorisation.
 *
 * Every response here is order-bearing, so both permission callbacks mark it
 * uncacheable (docs/SPEC.md Phase 8) before doing anything else — including
 * on the denial paths, since a cached 403 is still a cached fact about an order.
 *
 * @since 0.8.0
 */
final class RequestsController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'wpmphub/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	public const ROUTE = '/requests';

	/**
	 * Requests per window allowed per IP.
	 *
	 * @var int
	 */
	private const IP_LIMIT = 10;

	/**
	 * Requests per window allowed per billing-email hash.
	 *
	 * @var int
	 */
	private const EMAIL_LIMIT = 5;

	/**
	 * Requests per window allowed site-wide.
	 *
	 * @var int
	 */
	private const SITE_LIMIT = 200;

	/**
	 * Rate-limit window length.
	 *
	 * @var int
	 */
	private const WINDOW_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Rate-limiter bucket name.
	 *
	 * @var string
	 */
	private const RATE_LIMIT_BUCKET = 'requests_create';

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param OwnershipResolver $ownership    The one ownership choke point.
	 * @param RateLimiter       $rate_limiter Abuse throttling.
	 * @param RequestLifecycle  $service      Lookup by id and withdrawal.
	 * @param Cancel            $cancel       The only registered request-creating action so far.
	 * @param Logger            $logger       Logs every denial under the same reference id the customer sees.
	 */
	public function __construct(
		private OwnershipResolver $ownership,
		private RateLimiter $rate_limiter,
		private RequestLifecycle $service,
		private Cancel $cancel,
		private Logger $logger
	) {}

	/**
	 * Registers the routes.
	 *
	 * @since 0.8.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'authorise_create' ),
				'args'                => $this->create_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>[0-9]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'withdraw' ),
				'permission_callback' => array( $this, 'authorise_withdraw' ),
				'args'                => $this->withdraw_args(),
			)
		);
	}

	/**
	 * Schema for POST /requests.
	 *
	 * @since 0.8.0
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args(): array {
		return array(
			'order_id'    => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'reason_code' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				},
				'validate_callback' => static function ( $value ): bool {
					return null !== Sanitizer::reason_code( $value, Cancel::reason_codes() );
				},
			),
			'note'        => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? (string) $value : '';
				},
				'validate_callback' => static function ( $value ): bool {
					return is_scalar( $value );
				},
			),
			'token'       => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ): string {
					$candidate = is_scalar( $value ) ? (string) $value : '';

					return 1 === preg_match( '/^[A-Za-z0-9_-]*\.[a-f0-9]*$/', $candidate ) ? $candidate : '';
				},
				'validate_callback' => static function ( $value ): bool {
					return is_scalar( $value );
				},
			),
		);
	}

	/**
	 * Schema for DELETE /requests/{id}.
	 *
	 * @since 0.8.0
	 * @return array<string, array<string, mixed>>
	 */
	private function withdraw_args(): array {
		return array(
			'id'    => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'token' => $this->create_args()['token'],
		);
	}

	/**
	 * Permission check for POST /requests.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return true|\WP_Error
	 */
	public function authorise_create( \WP_REST_Request $request ) {
		Sanitizer::nocache();

		if ( ! $this->rate_limiter->allow_ip( self::RATE_LIMIT_BUCKET, self::client_ip(), self::IP_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'ip' ) );
		}

		if ( ! $this->rate_limiter->allow_site( self::RATE_LIMIT_BUCKET, self::SITE_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'site' ) );
		}

		self::supply_token( (string) $request->get_param( 'token' ) );

		$order = $this->authorise_order( (int) $request->get_param( 'order_id' ), 'rest:requests.create' );

		if ( $order instanceof \WP_Error ) {
			return $order;
		}

		if ( ! $this->rate_limiter->allow_email( self::RATE_LIMIT_BUCKET, $order->get_billing_email(), self::EMAIL_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'email' ) );
		}

		$request->set_param( 'wpmphub_order', $order );

		return true;
	}

	/**
	 * Creates a cancellation request.
	 *
	 * Eligibility is re-checked here by Cancel::execute() itself — the same
	 * check that decided whether a button was ever shown, run again against
	 * the order as it is right now, never trusted from the fact the client
	 * reached this far.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$order = $request->get_param( 'wpmphub_order' );

		if ( ! $order instanceof \WC_Order ) {
			return $this->deny( 'wpmphub_forbidden', __( 'This order could not be found.', 'wpmake-post-purchase-hub' ), 403, array() );
		}

		try {
			$created = $this->cancel->execute(
				$order,
				(string) $request->get_param( 'reason_code' ),
				(string) $request->get_param( 'note' ),
				is_user_logged_in() ? Request::SOURCE_ACCOUNT : Request::SOURCE_GUEST_TOKEN,
				get_current_user_id()
			);
		} catch ( IneligibleActionException $e ) {
			$status = EligibilityResponse::status_for( $e->result );
			$code   = 429 === $status ? 'wpmphub_cooldown' : 'wpmphub_ineligible';

			return $this->deny(
				$code,
				'' !== $e->result->message ? $e->result->message : __( 'This action is not currently available for this order.', 'wpmake-post-purchase-hub' ),
				$status,
				array(
					'order_id'    => $order->get_id(),
					'reason_code' => $e->result->reason_code,
				)
			);
		}

		return new \WP_REST_Response( self::request_shape( $created ), 201 );
	}

	/**
	 * Permission check for DELETE /requests/{id}.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return true|\WP_Error
	 */
	public function authorise_withdraw( \WP_REST_Request $request ) {
		Sanitizer::nocache();

		if ( ! $this->rate_limiter->allow_ip( self::RATE_LIMIT_BUCKET, self::client_ip(), self::IP_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'ip' ) );
		}

		self::supply_token( (string) $request->get_param( 'token' ) );

		$found = $this->service->find( (int) $request->get_param( 'id' ) );

		if ( null === $found ) {
			return $this->deny( 'wpmphub_forbidden', __( 'This request could not be found.', 'wpmake-post-purchase-hub' ), 403, array() );
		}

		$order = $this->authorise_order( $found->order_id, 'rest:requests.delete' );

		if ( $order instanceof \WP_Error ) {
			return $order;
		}

		$request->set_param( 'wpmphub_request', $found );

		return true;
	}

	/**
	 * Withdraws a pending request.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function withdraw( \WP_REST_Request $request ) {
		$found = $request->get_param( 'wpmphub_request' );

		if ( ! $found instanceof Request ) {
			return $this->deny( 'wpmphub_forbidden', __( 'This request could not be found.', 'wpmake-post-purchase-hub' ), 403, array() );
		}

		if ( ! $this->service->withdraw( $found ) ) {
			return $this->deny(
				'wpmphub_already_resolved',
				__( 'This request has already been resolved and can no longer be withdrawn.', 'wpmake-post-purchase-hub' ),
				409,
				array( 'request_id' => $found->id )
			);
		}

		$withdrawn = $this->service->find( $found->id );

		return new \WP_REST_Response( self::request_shape( $withdrawn ?? $found ), 200 );
	}

	/**
	 * Loads and authorises an order, mapping the resolver's denial to a REST error.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $context  Context label for OwnershipResolver.
	 * @return \WC_Order|\WP_Error
	 */
	private function authorise_order( int $order_id, string $context ) {
		try {
			return $this->ownership->assertCanAccess( $order_id, $context ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method name fixed by Security\OwnershipResolver.
		} catch ( AccessDeniedException $e ) {
			// Deliberately the same message regardless of reason: a customer
			// requesting another customer's order learns nothing about whether
			// that order exists. The reason_code still reaches the log.
			return $this->deny(
				'wpmphub_forbidden',
				__( 'You do not have access to this order.', 'wpmake-post-purchase-hub' ),
				403,
				array(
					'order_id'    => $order_id,
					'context'     => $context,
					'reason_code' => $e->reason_code,
				)
			);
		}
	}

	/**
	 * Supplies the guest token for the current request through the filter
	 * OwnershipResolver reads, for this request only.
	 *
	 * @since 0.8.0
	 *
	 * @param string $token Token from the request, already format-checked by the args schema.
	 * @return void
	 */
	private static function supply_token( string $token ): void {
		add_filter(
			'wpmphub_current_request_token',
			static function () use ( $token ): string {
				return $token;
			}
		);
	}

	/**
	 * The client's IP address, as a rate-limiting identity only.
	 *
	 * @since 0.8.0
	 * @return string
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * A rate-limit response, logged the same way every other denial is.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $log_context Extra context for the log line.
	 * @return \WP_Error
	 */
	private function too_many_requests( array $log_context ): \WP_Error {
		return $this->deny( 'wpmphub_rate_limited', __( 'Too many requests. Please try again later.', 'wpmake-post-purchase-hub' ), 429, $log_context );
	}

	/**
	 * Builds a denial response and logs it under the same reference id the
	 * customer sees, so a support conversation can find the exact log line.
	 *
	 * @since 0.8.0
	 *
	 * @param string               $code        Error code.
	 * @param string               $message     Human message, shown to the customer.
	 * @param int                  $status      HTTP status.
	 * @param array<string, mixed> $log_context Extra context for the log line.
	 * @return \WP_Error
	 */
	private function deny( string $code, string $message, int $status, array $log_context ): \WP_Error {
		$reference = self::reference();

		$this->logger->warning(
			$message,
			array_merge(
				$log_context,
				array(
					'code'      => $code,
					'status'    => $status,
					'reference' => $reference,
				)
			)
		);

		return new \WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'reference' => $reference,
			)
		);
	}

	/**
	 * A short id linking a customer-visible error to its log line.
	 *
	 * @since 0.8.0
	 * @return string
	 */
	private static function reference(): string {
		try {
			return bin2hex( random_bytes( 4 ) );
		} catch ( \Exception $e ) {
			unset( $e );

			return substr( md5( uniqid( '', true ) ), 0, 8 );
		}
	}

	/**
	 * The response shape for one request.
	 *
	 * @since 0.8.0
	 *
	 * @param Request $request Request to shape.
	 * @return array<string, mixed>
	 */
	private static function request_shape( Request $request ): array {
		return array(
			'id'                      => $request->id,
			'order_id'                => $request->order_id,
			'type'                    => $request->type,
			'status'                  => $request->status,
			'reason_code'             => $request->reason_code,
			'created_at'              => $request->created_at,
			'expected_response_hours' => Cancel::response_time_hours(),
		);
	}
}
