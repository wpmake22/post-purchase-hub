<?php
/**
 * REST controller for help submissions.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpTopics;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\Sanitizer;
use PostPurchaseHub\Support\Logger;

/**
 * `POST /pph/v1/help` — the route the help form submits to.
 *
 * Not in docs/SPEC.md's documented route list (`POST /requests`,
 * `GET /orders/{id}/timeline`, `POST /lookup`, `POST /reorder`), and added
 * deliberately rather than by omission: the same document's Phase 8 table
 * already requires rate limiting on "help-submit", so a submit endpoint was
 * always implied, and every other customer-side mutation in this plugin gets
 * its ownership check, its args schema and its throttling from being one of
 * these controllers. A second, hand-rolled transport for this one form would
 * have meant a second place for those three things to be got wrong.
 *
 * Order of checks mirrors `RequestsController` exactly: rate limit by IP and
 * site (cheap, no order needed) → the guest's token is supplied to the
 * ownership layer → `OwnershipResolver::assertCanAccess()` → rate limit by the
 * order's own billing email → `Help::submit()`'s own re-check. A logged-in
 * customer's `X-WP-Nonce` is verified by core's `rest_cookie_check_errors()`
 * before any of it runs.
 *
 * Tighter limits than the other routes carry, because this one ends in an
 * outbound email: a route that can make a store send mail is a spam relay if
 * it is not throttled harder than a route that cannot.
 *
 * @since 0.13.0
 */
final class HelpController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'pph/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	public const ROUTE = '/help';

	/**
	 * Submissions per window allowed per IP.
	 *
	 * @var int
	 */
	private const IP_LIMIT = 5;

	/**
	 * Submissions per window allowed per billing-email hash.
	 *
	 * @var int
	 */
	private const EMAIL_LIMIT = 3;

	/**
	 * Submissions per window allowed site-wide.
	 *
	 * @var int
	 */
	private const SITE_LIMIT = 100;

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
	private const RATE_LIMIT_BUCKET = 'help_submit';

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param OwnershipResolver $ownership    The one ownership choke point.
	 * @param RateLimiter       $rate_limiter Abuse throttling.
	 * @param Help              $help         The action this route executes.
	 * @param Logger            $logger       Logs every denial under the reference the customer sees.
	 */
	public function __construct(
		private OwnershipResolver $ownership,
		private RateLimiter $rate_limiter,
		private Help $help,
		private Logger $logger
	) {}

	/**
	 * Registers the route.
	 *
	 * @since 0.13.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => $this->args(),
			)
		);
	}

	/**
	 * Schema for POST /help.
	 *
	 * @since 0.13.0
	 * @return array<string, array<string, mixed>>
	 */
	private function args(): array {
		return array(
			'order_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'topic'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				},
				'validate_callback' => static function ( $value ): bool {
					return null !== HelpTopics::normalise( $value );
				},
			),
			'message'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? (string) $value : '';
				},
				'validate_callback' => static function ( $value ): bool {
					// Only the type and an upper bound here. The cap that
					// counts is applied by Sanitizer::note() after tags are
					// stripped, so markup cannot spend the budget.
					return is_scalar( $value ) && mb_strlen( (string) $value ) <= ( Help::MESSAGE_MAX_LENGTH * 4 );
				},
			),
			'token'    => array(
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
	 * Permission check for POST /help.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return true|\WP_Error
	 */
	public function authorise( \WP_REST_Request $request ) {
		Sanitizer::nocache();

		if ( ! $this->rate_limiter->allow_ip( self::RATE_LIMIT_BUCKET, self::client_ip(), self::IP_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'ip' ) );
		}

		if ( ! $this->rate_limiter->allow_site( self::RATE_LIMIT_BUCKET, self::SITE_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'site' ) );
		}

		self::supply_token( (string) $request->get_param( 'token' ) );

		$order_id = (int) $request->get_param( 'order_id' );

		try {
			$order = $this->ownership->assertCanAccess( $order_id, 'rest:help.submit' ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method name fixed by Security\OwnershipResolver.
		} catch ( AccessDeniedException $e ) {
			// Deliberately the same message whether the order does not exist or
			// belongs to someone else. The reason still reaches the log.
			return $this->deny(
				'pph_forbidden',
				__( 'You do not have access to this order.', 'wpmake-post-purchase-hub' ),
				403,
				array(
					'order_id'    => $order_id,
					'reason_code' => $e->reason_code,
				)
			);
		}

		if ( ! $this->rate_limiter->allow_email( self::RATE_LIMIT_BUCKET, $order->get_billing_email(), self::EMAIL_LIMIT, self::WINDOW_SECONDS ) ) {
			return $this->too_many_requests( array( 'stage' => 'email' ) );
		}

		$request->set_param( 'pph_order', $order );

		return true;
	}

	/**
	 * Hands a customer's question to the store.
	 *
	 * @since 0.13.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$order = $request->get_param( 'pph_order' );

		if ( ! $order instanceof \WC_Order ) {
			return $this->deny( 'pph_forbidden', __( 'This order could not be found.', 'wpmake-post-purchase-hub' ), 403, array() );
		}

		try {
			$this->help->submit(
				$order,
				(string) $request->get_param( 'topic' ),
				(string) $request->get_param( 'message' ),
				is_user_logged_in() ? Help::SOURCE_ACCOUNT : Help::SOURCE_GUEST
			);
		} catch ( IneligibleActionException $e ) {
			return $this->deny(
				'pph_ineligible',
				'' !== $e->result->message ? $e->result->message : __( 'This message could not be sent.', 'wpmake-post-purchase-hub' ),
				EligibilityResponse::status_for( $e->result ),
				array(
					'order_id'    => $order->get_id(),
					'reason_code' => $e->result->reason_code,
				)
			);
		}

		// Deliberately nothing about the submission comes back: the customer
		// wrote it, and echoing it into a response body would put it somewhere
		// it does not need to be.
		return new \WP_REST_Response(
			array(
				'submitted' => true,
				'message'   => __( 'Thanks — your message is on its way to the store, along with your order details.', 'wpmake-post-purchase-hub' ),
			),
			200
		);
	}

	/**
	 * Supplies the guest token for the current request through the filter
	 * `Security\OwnershipResolver` reads it from.
	 *
	 * @since 0.13.0
	 *
	 * @param string $token Token from the request, already format-checked by the args schema.
	 * @return void
	 */
	private static function supply_token( string $token ): void {
		add_filter(
			'pph_current_request_token',
			static function () use ( $token ): string {
				return $token;
			}
		);
	}

	/**
	 * The client's IP address, as a rate-limiting identity only.
	 *
	 * @since 0.13.0
	 * @return string
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * A rate-limit response, logged the same way every other denial is.
	 *
	 * @since 0.13.0
	 *
	 * @param array<string, mixed> $log_context Extra context for the log line.
	 * @return \WP_Error
	 */
	private function too_many_requests( array $log_context ): \WP_Error {
		return $this->deny( 'pph_rate_limited', __( 'Too many messages. Please try again later.', 'wpmake-post-purchase-hub' ), 429, $log_context );
	}

	/**
	 * Builds a denial response and logs it under the reference the customer sees.
	 *
	 * @since 0.13.0
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
	 * @since 0.13.0
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
}
