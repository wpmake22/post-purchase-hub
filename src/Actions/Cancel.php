<?php
/**
 * The cancellation-request action.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Integrations\Compat\HardExclusions;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Security\Sanitizer;

/**
 * Lets a customer request that an order be cancelled — never cancels it.
 *
 * Registers itself under the id `cancel`, deliberately the same key
 * WooCommerce core's own one-click cancel action uses in the My Account list
 * (`wc_get_account_orders_actions()`): `Frontend\ActionsRenderer` writes a
 * registered action's payload into the actions array under its own id, which
 * means this supersedes core's instant, no-approval cancel wherever this
 * action applies — added when eligible, removed when not — rather than the
 * two sitting side by side.
 *
 * `check()` is the one place eligibility for this action is decided, called
 * both by `resolve()` for rendering and by `execute()` for the REST
 * controller's re-check at the point of execution (docs/SPEC.md Phase 8: a
 * client's belief that a button was eligible is never trusted).
 *
 * @since 0.8.0
 */
final class Cancel {

	/**
	 * Action id. Deliberately matches WooCommerce core's own `cancel` key.
	 *
	 * @var string
	 */
	public const ID = 'cancel';

	/**
	 * Settings key for the allowed order statuses.
	 *
	 * @var string
	 */
	public const STATUSES_SETTING = 'cancel_allowed_statuses';

	/**
	 * Settings key for the cooldown between requests, in hours.
	 *
	 * @var string
	 */
	public const COOLDOWN_SETTING = 'cancel_cooldown_hours';

	/**
	 * Settings key for the per-order request cap.
	 *
	 * @var string
	 */
	public const CAP_SETTING = 'cancel_request_cap';

	/**
	 * Settings key for the expected merchant response time, in hours.
	 *
	 * @var string
	 */
	public const RESPONSE_TIME_SETTING = 'cancel_expected_response_hours';

	/**
	 * Order statuses the action applies to when nothing is configured.
	 *
	 * Deliberately broader than core's own `pending`/`failed`-only cancel:
	 * covering `processing` and `on-hold` too is this plugin's whole reason to
	 * exist — a request-and-approve path for orders core's instant cancel
	 * cannot safely touch.
	 *
	 * @var string[]
	 */
	public const DEFAULT_STATUSES = array( 'pending', 'failed', 'processing', 'on-hold' );

	/**
	 * Cooldown between requests when nothing is configured, in hours.
	 *
	 * @var int
	 */
	public const DEFAULT_COOLDOWN_HOURS = 24;

	/**
	 * Per-order request cap when nothing is configured.
	 *
	 * @var int
	 */
	public const DEFAULT_CAP = 3;

	/**
	 * Expected merchant response time when nothing is configured, in hours.
	 *
	 * @var int
	 */
	public const DEFAULT_RESPONSE_TIME_HOURS = 24;

	/**
	 * Settings key for whether approving a request restocks the order's items.
	 *
	 * @var string
	 */
	public const RESTOCK_SETTING = 'cancel_restock_on_approve';

	/**
	 * Whether approving restocks when nothing is configured.
	 *
	 * Matches WooCommerce's own refund screen, whose "Restock refunded items"
	 * checkbox defaults to checked — the same expectation a merchant already
	 * has for "cancel returns the stock" carries over here. There is no
	 * settings screen to change this yet (that is M14's job); until then this
	 * default is what every store gets.
	 *
	 * @var bool
	 */
	public const DEFAULT_RESTOCK_ON_APPROVE = true;

	/**
	 * Reason codes this install accepts when nothing is configured.
	 *
	 * @var string[]
	 */
	public const DEFAULT_REASON_CODES = array(
		'changed_mind',
		'found_better_price',
		'ordered_by_mistake',
		'shipping_too_slow',
		'other',
	);

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param EligibilityResolver $eligibility Eligibility engine.
	 * @param RequestLifecycle    $requests    Creates and withdraws the request row.
	 */
	public function __construct( private EligibilityResolver $eligibility, private RequestLifecycle $requests ) {}

	/**
	 * Registers this action against the registry.
	 *
	 * @since 0.8.0
	 *
	 * @param ActionRegistry $registry Registry to register against.
	 * @return void
	 */
	public function register( ActionRegistry $registry ): void {
		$registry->register(
			self::ID,
			self::label(),
			array( 'list', 'detail' ),
			\Closure::fromCallable( array( $this, 'resolve' ) )
		);
	}

	/**
	 * Render payload for one order and context, or null when ineligible.
	 *
	 * @since 0.8.0
	 *
	 * @param \WC_Order $order   Order to resolve against.
	 * @param string    $context Context being rendered, unused: the action reads the same either way.
	 * @return array<string, string>|null
	 */
	public function resolve( \WC_Order $order, string $context ): ?array {
		unset( $context );

		if ( ! $this->check( $order )->eligible ) {
			return null;
		}

		return array(
			'name' => self::label(),
			'url'  => '#pph-cancel-' . $order->get_id(),
		);
	}

	/**
	 * Whether cancellation may currently be requested for an order.
	 *
	 * @since 0.8.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function check( \WC_Order $order ): EligibilityResult {
		return $this->eligibility->resolve( self::ID, $order, $this->rule() );
	}

	/**
	 * Re-checks eligibility and creates the request if it still holds.
	 *
	 * @since 0.8.0
	 *
	 * @param \WC_Order $order       Order the request is against.
	 * @param string    $reason_code Candidate reason code.
	 * @param string    $note        Candidate customer note, already length-capped by the caller's schema.
	 * @param string    $source      One of Request::sources().
	 * @param int       $customer_id Logged-in customer id, 0 for a guest.
	 * @return Request
	 * @throws IneligibleActionException When the order is no longer eligible.
	 */
	public function execute( \WC_Order $order, string $reason_code, string $note, string $source, int $customer_id ): Request {
		$result = $this->check( $order );

		if ( ! $result->eligible ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor arg stored as a property, not the message; IneligibleActionException escapes its own message.
			throw new IneligibleActionException( $result );
		}

		$clean_reason = Sanitizer::reason_code( $reason_code, self::reason_codes() );

		return $this->requests->create(
			array(
				'order_id'            => $order->get_id(),
				'customer_id'         => $customer_id,
				'customer_email_hash' => Sanitizer::hash_email( $order->get_billing_email() ),
				'type'                => Request::TYPE_CANCELLATION,
				'reason_code'         => $clean_reason,
				'customer_note'       => '' === $note ? null : Sanitizer::note( $note ),
				'source'              => $source,
			)
		);
	}

	/**
	 * Transitions an order to cancelled and optionally restocks it.
	 *
	 * Never issues a refund and never will — see docs/SPEC.md "The refund
	 * decision". A merchant refunds through Woo's own refund UI, one click
	 * away from the notification this triggers.
	 *
	 * Callers (`Admin\RequestActionController`) are expected to have already
	 * resolved the request row before calling this: `update_status()` fires
	 * `woocommerce_order_status_changed` synchronously, which is also what
	 * closes a stale pending cancellation request when an order is cancelled
	 * by some other route (`Plugin::reconcile_pending_cancellation()`).
	 * Resolving the request first means that generic handler finds nothing
	 * left to reconcile when this method runs.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order $order   Order to cancel.
	 * @param int       $user_id Staff member approving the request, for the order note.
	 * @return bool True when the order transitioned here. False when it was
	 *              already cancelled by another route — the caller's job to
	 *              reconcile, not this method's to transition twice.
	 */
	public function approve( \WC_Order $order, int $user_id ): bool {
		if ( $order->has_status( 'cancelled' ) ) {
			return false;
		}

		$order->update_status( 'cancelled', '', true );

		if ( self::restock_on_approve() ) {
			wc_increase_stock_levels( $order );
		}

		$order->add_order_note( self::approval_note_text( $user_id ), 0, false );

		return true;
	}

	/**
	 * Whether approving a cancellation request restocks the order's items.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public static function restock_on_approve(): bool {
		$settings = self::settings();

		return isset( $settings[ self::RESTOCK_SETTING ] )
			? (bool) $settings[ self::RESTOCK_SETTING ]
			: self::DEFAULT_RESTOCK_ON_APPROVE;
	}

	/**
	 * The order note text written when a cancellation request is approved.
	 *
	 * @since 0.9.0
	 *
	 * @param int $user_id Staff member approving the request, 0 when unknown.
	 * @return string
	 */
	private static function approval_note_text( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$name = $user instanceof \WP_User ? $user->display_name : __( 'a store manager', 'post-purchase-hub' );

		/* translators: 1: staff member's display name, 2: date and time the approval happened. */
		$format = __( 'Cancellation request approved by %1$s on %2$s.', 'post-purchase-hub' );

		return sprintf( $format, $name, wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'H:i' ) ) );
	}

	/**
	 * The translated action label.
	 *
	 * Copy requirement (docs/SPEC.md Phase 9): this says "request", never
	 * "cancel order" — the UI must never claim a completed cancellation this
	 * action does not perform.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Request cancellation', 'post-purchase-hub' );
	}

	/**
	 * Reason codes this install accepts, filterable.
	 *
	 * @since 0.8.0
	 *
	 * @return string[]
	 */
	public static function reason_codes(): array {
		/**
		 * Filters the reason codes a customer may choose when requesting a cancellation.
		 *
		 * @since 0.8.0
		 *
		 * @param string[] $codes Accepted reason codes.
		 */
		$codes = apply_filters( 'pph_cancel_reason_codes', self::DEFAULT_REASON_CODES );

		return is_array( $codes ) && array() !== $codes ? array_values( $codes ) : self::DEFAULT_REASON_CODES;
	}

	/**
	 * Human labels for this install's reason codes, for the request form's
	 * reason select.
	 *
	 * A code a filter added to reason_codes() without a matching label here
	 * still gets one — humanised from the slug — rather than an empty option.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, string>
	 */
	public static function reason_code_labels(): array {
		$defaults = array(
			'changed_mind'       => __( 'I changed my mind', 'post-purchase-hub' ),
			'found_better_price' => __( 'I found a better price elsewhere', 'post-purchase-hub' ),
			'ordered_by_mistake' => __( 'I ordered this by mistake', 'post-purchase-hub' ),
			'shipping_too_slow'  => __( 'Shipping is taking too long', 'post-purchase-hub' ),
			'other'              => __( 'Other', 'post-purchase-hub' ),
		);

		/**
		 * Filters the human labels shown for cancellation reason codes.
		 *
		 * @since 0.8.0
		 *
		 * @param array<string, string> $labels Labels keyed by reason code.
		 */
		$labels = apply_filters( 'pph_cancel_reason_code_labels', $defaults );
		$labels = is_array( $labels ) ? $labels : $defaults;

		$result = array();

		foreach ( self::reason_codes() as $code ) {
			$result[ $code ] = isset( $labels[ $code ] ) && is_string( $labels[ $code ] ) && '' !== $labels[ $code ]
				? $labels[ $code ]
				: ucfirst( str_replace( '_', ' ', $code ) );
		}

		return $result;
	}

	/**
	 * The configured expected merchant response time, in hours.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	public static function response_time_hours(): int {
		$settings = self::settings();
		$hours    = isset( $settings[ self::RESPONSE_TIME_SETTING ] ) ? (int) $settings[ self::RESPONSE_TIME_SETTING ] : self::DEFAULT_RESPONSE_TIME_HOURS;

		return max( 1, $hours );
	}

	/**
	 * This action's eligibility rule, built from settings and the hard exclusions.
	 *
	 * @since 0.8.0
	 *
	 * @return EligibilityRule
	 */
	private function rule(): EligibilityRule {
		$settings = self::settings();

		$statuses = isset( $settings[ self::STATUSES_SETTING ] ) && is_array( $settings[ self::STATUSES_SETTING ] )
			? $settings[ self::STATUSES_SETTING ]
			: self::DEFAULT_STATUSES;

		$cooldown_hours = isset( $settings[ self::COOLDOWN_SETTING ] ) ? (int) $settings[ self::COOLDOWN_SETTING ] : self::DEFAULT_COOLDOWN_HOURS;
		$cap            = isset( $settings[ self::CAP_SETTING ] ) ? (int) $settings[ self::CAP_SETTING ] : self::DEFAULT_CAP;

		return new EligibilityRule(
			allowed_statuses: array_values( $statuses ),
			excluded_order_types: HardExclusions::order_types(),
			excluded_product_types: HardExclusions::product_types(),
			per_order_cap: max( 1, $cap ),
			cooldown_seconds: max( 0, $cooldown_hours ) * HOUR_IN_SECONDS,
			// This action registers under id `cancel` (to supersede WooCommerce
			// core's own list-action key), but stored requests carry
			// Request::TYPE_CANCELLATION — a different string — so the cap and
			// cooldown checks are told explicitly which one to query by.
			history_type: Request::TYPE_CANCELLATION
		);
	}

	/**
	 * The plugin's settings option, defensively typed.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$settings = get_option( 'pph_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}
}
