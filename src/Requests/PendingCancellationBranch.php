<?php
/**
 * Timeline branch data for a pending cancellation request.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\RequestHistory;

/**
 * What the order-detail timeline shows while a cancellation request is open.
 *
 * A pending request does not change the order's WooCommerce status, so it can
 * never be a `Timeline\StageMap` branch — that vocabulary is entirely
 * status-derived, and stays that way. This is a presentation-time overlay
 * `Frontend\Renderer` applies only on the single-order detail view, never on
 * the orders list: finding this out costs one query, which the list's
 * zero-added-queries-per-row rule (M04) does not allow but a single order
 * view never had to avoid.
 *
 * Depends on Actions\RequestHistory rather than the concrete
 * RequestRepository, for the same reason EligibilityResolver does: a fast,
 * no-bootstrap unit test over what this class decides, not another one that
 * needs a real `$wpdb` to prove RequestRepository's own query is correct.
 *
 * @since 0.8.0
 */
final class PendingCancellationBranch {

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param RequestHistory $requests Backing store for the pending-request lookup.
	 */
	public function __construct( private RequestHistory $requests ) {}

	/**
	 * The branch overlay for one order, or null when there is nothing pending.
	 *
	 * @since 0.8.0
	 *
	 * @param \WC_Order $order Order to check.
	 * @return array{label: string, timestamp_utc: string, note: string}|null
	 */
	public function for_order( \WC_Order $order ): ?array {
		$pending = $this->requests->pending_for_order( $order->get_id(), Request::TYPE_CANCELLATION );

		if ( null === $pending ) {
			return null;
		}

		return array(
			'label'         => __( 'Cancellation requested', 'wpmake-post-purchase-hub' ),
			'timestamp_utc' => $pending->created_at,
			'note'          => self::expected_response_note(),
		);
	}

	/**
	 * A sentence naming the configured expected response time.
	 *
	 * Reads the same setting Actions\Cancel reads, by its constant rather
	 * than by calling into it: a cross-domain method call would make this
	 * class depend on one specific action, where a constant is the same kind
	 * of reference `Security\TokenService` already makes to
	 * `Install\Activator::TOKEN_SECRET_OPTION`.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	private static function expected_response_note(): string {
		$settings = get_option( 'wpmphub_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$hours = isset( $settings[ Cancel::RESPONSE_TIME_SETTING ] )
			? max( 1, (int) $settings[ Cancel::RESPONSE_TIME_SETTING ] )
			: Cancel::DEFAULT_RESPONSE_TIME_HOURS;

		$days = (int) round( $hours / 24 );

		if ( $days >= 1 && 0 === $hours % 24 ) {
			return sprintf(
				/* translators: %d: number of days. */
				_n( 'We usually respond within %d day.', 'We usually respond within %d days.', $days, 'wpmake-post-purchase-hub' ),
				$days
			);
		}

		return sprintf(
			/* translators: %d: number of hours. */
			_n( 'We usually respond within %d hour.', 'We usually respond within %d hours.', $hours, 'wpmake-post-purchase-hub' ),
			$hours
		);
	}
}
