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
		 * Product id.
		 *
		 * @var int
		 */
		private int $id = 0;

		/**
		 * Parent product id, non-zero for a variation.
		 *
		 * @var int
		 */
		private int $parent_id = 0;

		/**
		 * Product name.
		 *
		 * @var string
		 */
		private string $name = '';

		/**
		 * Current price.
		 *
		 * @var string
		 */
		private string $price = '';

		/**
		 * Whether the product may be bought at all.
		 *
		 * @var bool
		 */
		private bool $purchasable = true;

		/**
		 * Units that may be bought right now: 0 none, -1 unlimited.
		 *
		 * @var int
		 */
		private int $max_purchase_quantity = -1;

		/**
		 * Whether this product tracks stock.
		 *
		 * Defaults to false, matching this stub's documented reading of
		 * `max_purchase_quantity`: -1 means unlimited. That is only true of an
		 * unmanaged product — a stock-managed one returns its stock quantity
		 * verbatim, and -1 there means oversold by one. A test that wants the
		 * oversold case says so with `set_managing_stock( true )`.
		 *
		 * @var bool
		 */
		private bool $managing_stock = false;

		/**
		 * Whether backorders are accepted.
		 *
		 * @var bool
		 */
		private bool $backorders_allowed = false;

		/**
		 * Permalink.
		 *
		 * @var string
		 */
		private string $permalink = '';

		/**
		 * Constructor.
		 *
		 * @param string $type Product type slug.
		 * @param int    $id   Product id.
		 */
		public function __construct( string $type = 'simple', int $id = 0 ) {
			$this->type = $type;
			$this->id   = $id;
		}

		/**
		 * Returns the product id.
		 *
		 * @return int
		 */
		public function get_id(): int {
			return $this->id;
		}

		/**
		 * Returns the parent product id.
		 *
		 * @return int
		 */
		public function get_parent_id(): int {
			return $this->parent_id;
		}

		/**
		 * Sets the parent product id.
		 *
		 * @param int $parent_id Parent product id.
		 * @return void
		 */
		public function set_parent_id( int $parent_id ): void {
			$this->parent_id = $parent_id;
		}

		/**
		 * Returns the product name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Sets the product name.
		 *
		 * @param string $name Product name.
		 * @return void
		 */
		public function set_name( string $name ): void {
			$this->name = $name;
		}

		/**
		 * Returns the current price.
		 *
		 * @return string
		 */
		public function get_price(): string {
			return $this->price;
		}

		/**
		 * Sets the current price.
		 *
		 * @param float|string $price Current price.
		 * @return void
		 */
		public function set_price( $price ): void {
			$this->price = (string) $price;
		}

		/**
		 * Whether the product may be bought at all.
		 *
		 * @return bool
		 */
		public function is_purchasable(): bool {
			return $this->purchasable;
		}

		/**
		 * Sets whether the product may be bought at all.
		 *
		 * @param bool $purchasable Whether it is purchasable.
		 * @return void
		 */
		public function set_purchasable( bool $purchasable ): void {
			$this->purchasable = $purchasable;
		}

		/**
		 * Units that may be bought right now: 0 none, -1 unlimited.
		 *
		 * @return int
		 */
		public function get_max_purchase_quantity(): int {
			return $this->max_purchase_quantity;
		}

		/**
		 * Sets the units that may be bought right now.
		 *
		 * @param int $quantity 0 none, -1 unlimited, otherwise the limit.
		 * @return void
		 */
		public function set_max_purchase_quantity( int $quantity ): void {
			$this->max_purchase_quantity = $quantity;
		}

		/**
		 * Whether this product tracks stock.
		 *
		 * Needed because a negative `get_max_purchase_quantity()` is ambiguous
		 * in WooCommerce's own API — -1 means "no limit", but a stock-managed
		 * product returns its stock quantity verbatim, which goes negative when
		 * a store oversells. Callers disambiguate with this pair.
		 *
		 * @return bool
		 */
		public function managing_stock(): bool {
			return $this->managing_stock;
		}

		/**
		 * Sets whether this product tracks stock.
		 *
		 * @param bool $managing Whether stock is managed.
		 * @return void
		 */
		public function set_managing_stock( bool $managing ): void {
			$this->managing_stock = $managing;
		}

		/**
		 * Whether the store will accept orders beyond available stock.
		 *
		 * @return bool
		 */
		public function backorders_allowed(): bool {
			return $this->backorders_allowed;
		}

		/**
		 * Sets whether backorders are accepted.
		 *
		 * @param bool $allowed Whether backorders are accepted.
		 * @return void
		 */
		public function set_backorders_allowed( bool $allowed ): void {
			$this->backorders_allowed = $allowed;
		}

		/**
		 * Returns the permalink.
		 *
		 * @return string
		 */
		public function get_permalink(): string {
			return $this->permalink;
		}

		/**
		 * Sets the permalink.
		 *
		 * @param string $permalink Permalink.
		 * @return void
		 */
		public function set_permalink( string $permalink ): void {
			$this->permalink = $permalink;
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
