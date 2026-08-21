<?php
/**
 * WC_Order_Item_Shipping stub for the unit suite.
 *
 * Just enough of the shipping line item to compute an estimated-delivery
 * range against: a method id and the order it belongs to. Guarded, so
 * loading this where the real WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Order_Item_Shipping' ) ) {
	/**
	 * Stand-in for a shipping line item, covering only its method id.
	 */
	class WC_Order_Item_Shipping {

		/**
		 * Shipping method id, e.g. `flat_rate`.
		 *
		 * @var string
		 */
		private string $method_id;

		/**
		 * Order id the item belongs to.
		 *
		 * @var int
		 */
		private int $order_id;

		/**
		 * Constructor.
		 *
		 * @param string $method_id Shipping method id.
		 * @param int    $order_id  Order id the item belongs to.
		 */
		public function __construct( string $method_id = '', int $order_id = 0 ) {
			$this->method_id = $method_id;
			$this->order_id  = $order_id;
		}

		/**
		 * Returns the shipping method id.
		 *
		 * @return string
		 */
		public function get_method_id(): string {
			return $this->method_id;
		}

		/**
		 * Returns the order id this item belongs to.
		 *
		 * @return int
		 */
		public function get_order_id(): int {
			return $this->order_id;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
