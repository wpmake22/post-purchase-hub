<?php
/**
 * WC_Order stub for the unit suite.
 *
 * Just enough of the CRUD surface to be exercised: meta that reads back the way
 * WooCommerce returns it, a save counter, and mutable dates. Guarded, so
 * loading this where the real WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Stand-in for a WooCommerce order, covering the CRUD surface we use.
	 */
	class WC_Order {

		/**
		 * Order id.
		 *
		 * @var int
		 */
		private int $id;

		/**
		 * Unprefixed order status.
		 *
		 * @var string
		 */
		private string $status;

		/**
		 * Meta values, keyed by meta key.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta = array();

		/**
		 * Dates, keyed by name.
		 *
		 * @var array<string, WC_DateTime|null>
		 */
		private array $dates = array(
			'created'   => null,
			'paid'      => null,
			'completed' => null,
		);

		/**
		 * How many times save() has been called.
		 *
		 * @var int
		 */
		public int $saves = 0;

		/**
		 * Shipping line items.
		 *
		 * @var WC_Order_Item_Shipping[]
		 */
		private array $shipping_methods = array();

		/**
		 * Constructor.
		 *
		 * @param int    $id     Order id.
		 * @param string $status Unprefixed status.
		 */
		public function __construct( int $id = 1, string $status = 'pending' ) {
			$this->id     = $id;
			$this->status = $status;
		}

		/**
		 * Returns the order id.
		 *
		 * @return int
		 */
		public function get_id(): int {
			return $this->id;
		}

		/**
		 * Returns the unprefixed status.
		 *
		 * @return string
		 */
		public function get_status(): string {
			return $this->status;
		}

		/**
		 * Sets the unprefixed status.
		 *
		 * @param string $status Status.
		 * @return void
		 */
		public function set_status( string $status ): void {
			$this->status = $status;
		}

		/**
		 * Returns a meta value, empty string when absent, as WooCommerce does.
		 *
		 * @param string $key    Meta key.
		 * @param bool   $single Whether to return a single value.
		 * @return mixed
		 */
		public function get_meta( string $key = '', bool $single = true ) {
			unset( $single );

			return $this->meta[ $key ] ?? '';
		}

		/**
		 * Writes a meta value in memory.
		 *
		 * @param string $key   Meta key.
		 * @param mixed  $value Meta value.
		 * @return void
		 */
		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * Removes a meta value.
		 *
		 * @param string $key Meta key.
		 * @return void
		 */
		public function delete_meta_data( string $key ): void {
			unset( $this->meta[ $key ] );
		}

		/**
		 * Counts a save.
		 *
		 * @return int
		 */
		public function save(): int {
			++$this->saves;

			return $this->id;
		}

		/**
		 * Returns the customer-facing order number.
		 *
		 * @return string
		 */
		public function get_order_number(): string {
			return (string) $this->id;
		}

		/**
		 * Returns the customer's link to this order.
		 *
		 * @return string
		 */
		public function get_view_order_url(): string {
			return 'https://example.test/my-account/view-order/' . $this->id . '/';
		}

		/**
		 * Returns the creation date.
		 *
		 * @return WC_DateTime|null
		 */
		public function get_date_created(): ?WC_DateTime {
			return $this->dates['created'];
		}

		/**
		 * Returns the payment date.
		 *
		 * @return WC_DateTime|null
		 */
		public function get_date_paid(): ?WC_DateTime {
			return $this->dates['paid'];
		}

		/**
		 * Returns the completion date.
		 *
		 * @return WC_DateTime|null
		 */
		public function get_date_completed(): ?WC_DateTime {
			return $this->dates['completed'];
		}

		/**
		 * Sets one of the stored dates.
		 *
		 * @param string           $name Date name: created, paid or completed.
		 * @param WC_DateTime|null $date Date.
		 * @return void
		 */
		public function set_date( string $name, ?WC_DateTime $date ): void {
			$this->dates[ $name ] = $date;
		}

		/**
		 * Returns the order's shipping line items.
		 *
		 * @return WC_Order_Item_Shipping[]
		 */
		public function get_shipping_methods(): array {
			return $this->shipping_methods;
		}

		/**
		 * Sets the order's shipping line items.
		 *
		 * @param WC_Order_Item_Shipping[] $shipping_methods Shipping line items.
		 * @return void
		 */
		public function set_shipping_methods( array $shipping_methods ): void {
			$this->shipping_methods = $shipping_methods;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
