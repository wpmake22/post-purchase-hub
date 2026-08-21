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
		 * Parent product id, as stored on the line.
		 *
		 * @var int
		 */
		private int $product_id = 0;

		/**
		 * Variation id, 0 for a non-variable line.
		 *
		 * @var int
		 */
		private int $variation_id = 0;

		/**
		 * Quantity bought.
		 *
		 * @var int
		 */
		private int $quantity = 1;

		/**
		 * Line subtotal, before discounts and excluding tax.
		 *
		 * @var string
		 */
		private string $subtotal = '0';

		/**
		 * Line name as it was bought.
		 *
		 * @var string
		 */
		private string $name = '';

		/**
		 * Meta data, in WooCommerce's object shape.
		 *
		 * @var list<object>
		 */
		private array $meta_data = array();

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

		/**
		 * Returns the parent product id.
		 *
		 * @return int
		 */
		public function get_product_id(): int {
			return $this->product_id;
		}

		/**
		 * Sets the parent product id.
		 *
		 * @param int $product_id Parent product id.
		 * @return void
		 */
		public function set_product_id( int $product_id ): void {
			$this->product_id = $product_id;
		}

		/**
		 * Returns the variation id.
		 *
		 * @return int
		 */
		public function get_variation_id(): int {
			return $this->variation_id;
		}

		/**
		 * Sets the variation id.
		 *
		 * @param int $variation_id Variation id.
		 * @return void
		 */
		public function set_variation_id( int $variation_id ): void {
			$this->variation_id = $variation_id;
		}

		/**
		 * Returns the quantity bought.
		 *
		 * @return int
		 */
		public function get_quantity(): int {
			return $this->quantity;
		}

		/**
		 * Sets the quantity bought.
		 *
		 * @param int $quantity Quantity.
		 * @return void
		 */
		public function set_quantity( int $quantity ): void {
			$this->quantity = $quantity;
		}

		/**
		 * Returns the line subtotal.
		 *
		 * @return string
		 */
		public function get_subtotal(): string {
			return $this->subtotal;
		}

		/**
		 * Sets the line subtotal.
		 *
		 * @param float|string $subtotal Line subtotal.
		 * @return void
		 */
		public function set_subtotal( $subtotal ): void {
			$this->subtotal = (string) $subtotal;
		}

		/**
		 * Returns the line name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Sets the line name.
		 *
		 * @param string $name Line name.
		 * @return void
		 */
		public function set_name( string $name ): void {
			$this->name = $name;
		}

		/**
		 * Returns the line's meta data.
		 *
		 * @return list<object>
		 */
		public function get_meta_data(): array {
			return $this->meta_data;
		}

		/**
		 * Sets the line's meta data from key => value pairs.
		 *
		 * @param array<string, string> $meta Meta pairs.
		 * @return void
		 */
		public function set_meta_pairs( array $meta ): void {
			$this->meta_data = array();

			foreach ( $meta as $key => $value ) {
				$this->meta_data[] = (object) array(
					'key'   => (string) $key,
					'value' => (string) $value,
				);
			}
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
