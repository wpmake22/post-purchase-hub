<?php
/**
 * Stub tracking-availability check.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Tracking;

/**
 * Reports no tracking data until a real adapter says otherwise.
 *
 * The safe default: with no adapter installed, every order behaves as if it
 * has no tracking, so estimated delivery keeps showing. `pph_has_tracking_data`
 * is the escape hatch for a store that already has tracking some other way —
 * a merchant's own code, or a tracking plugin's own integration — without
 * waiting on this plugin's adapter layer to exist.
 *
 * @since 0.5.0
 */
final class NullTrackingAvailability implements TrackingAvailability {

	/**
	 * Always false unless filtered.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to check.
	 * @return bool
	 */
	public function has_tracking( \WC_Order $order ): bool {
		/**
		 * Filters whether real tracking data exists for an order.
		 *
		 * @since 0.5.0
		 *
		 * @param bool      $has_tracking Whether tracking data exists. Defaults to false.
		 * @param \WC_Order $order        Order being checked.
		 */
		return (bool) apply_filters( 'pph_has_tracking_data', false, $order );
	}
}
