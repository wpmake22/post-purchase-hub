<?php
/**
 * WC_Order_Item_Product stub for the unit suite.
 *
 * Just enough of a product line item to evaluate a product-type exclusion:
 * the product it resolves to. Guarded, so loading this where the real
 * WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/**
	 * Stand-in for a product line item, covering only the product it resolves to.
	 */
	class WC_Order_Item_Product {

		/**
		 * Product this line item resolves to, or null when it has been deleted.
		 *
		 * @var WC_Product|null
		 */
		private ?WC_Product $product;

		/**
		 * Constructor.
		 *
		 * @param WC_Product|null $product Product this line item resolves to.
		 */
		public function __construct( ?WC_Product $product = null ) {
			$this->product = $product;
		}

		/**
		 * Returns the product this line item resolves to.
		 *
		 * @return WC_Product|null
		 */
		public function get_product(): ?WC_Product {
			return $this->product;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
