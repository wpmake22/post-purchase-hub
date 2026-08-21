<?php
/**
 * WC_Product stub for the unit suite.
 *
 * Just enough of a product to evaluate a product-type exclusion: a type
 * slug and an is_type() comparison. Guarded, so loading this where the real
 * WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Stand-in for a WooCommerce product, covering only its type.
	 */
	class WC_Product {

		/**
		 * Product type slug, e.g. `simple`, `booking`, `subscription`.
		 *
		 * @var string
		 */
		private string $type;

		/**
		 * Constructor.
		 *
		 * @param string $type Product type slug.
		 */
		public function __construct( string $type = 'simple' ) {
			$this->type = $type;
		}

		/**
		 * Returns the product type slug.
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * Whether the product is of a given type.
		 *
		 * @param string $type Type slug to compare against.
		 * @return bool
		 */
		public function is_type( string $type ): bool {
			return $this->type === $type;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
