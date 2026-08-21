<?php
/**
 * REST controller for reorder confirmation.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderLine;
use PostPurchaseHub\Actions\ReorderOutcome;
use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\Sanitizer;
use PostPurchaseHub\Support\Logger;

/**
 * `POST /pph/v1/reorder` — the only route in this plugin that writes a cart.
 *
 * POST, not GET, for the reason CLAUDE.md hard rule 4 exists and core's own
 * `order_again` link ignores: a corporate mail scanner or a link prefetcher
 * that follows a customer's order-page links must not be able to fill their
 * cart. The reconciliation summary is what the link leads to; this is what the
 * button on it submits.
 *
 * Order of checks mirrors `RequestsController`: rate limit by IP and site
 * (cheap, no order needed) → `OwnershipResolver::assertCanAccess()` → rate
 * limit by the order's own billing email → the action's own re-check inside
 * `Reorder::execute()`. A logged-in customer's `X-WP-Nonce` is verified by
 * core's `rest_cookie_check_errors()` before any of it runs.
 *
 * No `token` parameter, unlike the requests route: a cart belongs to a
 * session, and `Reorder::check()` requires an account, so a signed guest token
 * would authorise reaching an order it still could not reorder. Offering the
 * parameter would only imply otherwise.
 *
 * @since 0.12.0
 */
final class ReorderController {

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
	public const ROUTE = '/reorder';

	/**
	 * Confirmations per window allowed per IP.
	 *
	 * @var int
	 */
	private const IP_LIMIT = 20;

	/**
	 * Confirmations per window allowed per billing-email hash.
	 *
	 * @var int
	 */
	private const EMAIL_LIMIT = 10;

	/**
	 * Confirmations per window allowed site-wide.
	 *
	 * @var int
	 */
	private const SITE_LIMIT = 300;

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
	private const RATE_LIMIT_BUCKET = 'reorder';

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param OwnershipResolver $ownership    The one ownership choke point.
	 * @param RateLimiter       $rate_limiter Abuse throttling.
	 * @param Reorder           $reorder      The action this route executes.
	 * @param Logger            $logger       Logs every denial under the reference the customer sees.
	 */
	public function __construct(
		private OwnershipResolver $ownership,
		private RateLimiter $rate_limiter,
		private Reorder $reorder,
		private Logger $logger
	) {}

	/**
	 * Registers the route.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => $this->args(),
			)
		);
	}

	/**
	 * Schema for POST /reorder.
	 *
	 * @since 0.12.0
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
			'mode'     => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => Reorder::MODE_MERGE,
				'enum'              => Reorder::modes(),
				'sanitize_callback' => static function ( $value ): string {
					return Reorder::normalise_mode( is_scalar( $value ) ? (string) $value : '' );
				},
				'validate_callback' => static function ( $value ): bool {
					return is_scalar( $value ) && in_array( (string) $value, Reorder::modes(), true );
				},
			),
		);
	}

	/**
	 * Permission check for POST /reorder.
	 *
	 * @since 0.12.0
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

		$order_id = (int) $request->get_param( 'order_id' );

		try {
			$order = $this->ownership->assertCanAccess( $order_id, 'rest:reorder.confirm' ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method name fixed by Security\OwnershipResolver.
		} catch ( AccessDeniedException $e ) {
			// Deliberately the same message whether the order does not exist or
			// belongs to someone else. The reason still reaches the log.
			return $this->deny(
				'pph_forbidden',
				__( 'You do not have access to this order.', 'post-purchase-hub' ),
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
	 * Updates the cart from a past order.
	 *
	 * @since 0.12.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( \WP_REST_Request $request ) {
		$order = $request->get_param( 'pph_order' );

		if ( ! $order instanceof \WC_Order ) {
			return $this->deny( 'pph_forbidden', __( 'This order could not be found.', 'post-purchase-hub' ), 403, array() );
		}

		try {
			$outcome = $this->reorder->execute( $order, (string) $request->get_param( 'mode' ) );
		} catch ( IneligibleActionException $e ) {
			$status = EligibilityResponse::status_for( $e->result );

			return $this->deny(
				Reorder::REASON_NOTHING_AVAILABLE === $e->result->reason_code ? 'pph_nothing_available' : 'pph_ineligible',
				'' !== $e->result->message ? $e->result->message : __( 'This order cannot be bought again right now.', 'post-purchase-hub' ),
				$status,
				array(
					'order_id'    => $order->get_id(),
					'reason_code' => $e->result->reason_code,
				)
			);
		}

		return new \WP_REST_Response( $this->outcome_shape( $outcome ), 200 );
	}

	/**
	 * The response shape for one completed reorder.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderOutcome $outcome Outcome to shape.
	 * @return array<string, mixed>
	 */
	private function outcome_shape( ReorderOutcome $outcome ): array {
		return array(
			'mode'           => $outcome->mode,
			'added'          => array_map( array( $this, 'line_shape' ), $outcome->added ),
			'rejected'       => array_map( array( $this, 'line_shape' ), $outcome->rejected ),
			'added_count'    => $outcome->added_count(),
			'rejected_count' => count( $outcome->rejected ),
			'cart_url'       => $this->reorder->cart_url(),
			'cart_items'     => $this->reorder->cart_item_count(),
		);
	}

	/**
	 * The response shape for one line.
	 *
	 * Deliberately narrow: a name, what happened to it and how many. Prices
	 * belong to the summary the customer already read, and repeating them in a
	 * response body would put order data somewhere it does not need to be.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line to shape.
	 * @return array<string, mixed>
	 */
	private function line_shape( ReorderLine $line ): array {
		return array(
			'name'     => $line->name,
			'outcome'  => $line->outcome,
			'quantity' => $line->quantity,
		);
	}

	/**
	 * The client's IP address, as a rate-limiting identity only.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * A rate-limit response, logged the same way every other denial is.
	 *
	 * @since 0.12.0
	 *
	 * @param array<string, mixed> $log_context Extra context for the log line.
	 * @return \WP_Error
	 */
	private function too_many_requests( array $log_context ): \WP_Error {
		return $this->deny( 'pph_rate_limited', __( 'Too many requests. Please try again later.', 'post-purchase-hub' ), 429, $log_context );
	}

	/**
	 * Builds a denial response and logs it under the reference the customer sees.
	 *
	 * @since 0.12.0
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
	 * @since 0.12.0
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
