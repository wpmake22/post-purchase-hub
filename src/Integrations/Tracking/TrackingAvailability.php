<?php
/**
 * Tracking-availability check contract.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Tracking;

/**
 * Answers one question: does real tracking data exist for this order?
 *
 * Nothing in this milestone builds the adapter layer the architecture
 * document describes for AST, the official Shipment Tracking extension and
 * Woo's native fulfilment data — that is undelivered work with no milestone
 * of its own yet. Estimated delivery only needs to know whether *something*
 * more precise than a computed range already exists, so it depends on this
 * narrow interface instead of waiting on that layer. Whichever milestone
 * builds the adapters swaps `NullTrackingAvailability` for a real
 * implementation in `Services::register()` and this class never changes.
 *
 * @since 0.5.0
 */
interface TrackingAvailability {

	/**
	 * Whether real tracking data exists for an order.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to check.
	 * @return bool
	 */
	public function has_tracking( \WC_Order $order ): bool;
}
