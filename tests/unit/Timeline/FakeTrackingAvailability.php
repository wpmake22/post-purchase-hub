<?php
/**
 * Tracking-availability test double.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;

/**
 * Reports a fixed answer, so EstimatedDelivery's suppression can be tested
 * without depending on NullTrackingAvailability's own filter mechanism.
 *
 * @since 0.5.0
 */
final class FakeTrackingAvailability implements TrackingAvailability {

	/**
	 * Answer to report.
	 *
	 * @var bool
	 */
	public bool $has_tracking = false;

	/**
	 * Returns the fixed answer.
	 *
	 * @param \WC_Order $order Unused.
	 * @return bool
	 */
	public function has_tracking( \WC_Order $order ): bool {
		unset( $order );

		return $this->has_tracking;
	}
}
