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
		 * Customer user id, 0 for a guest order.
		 *
		 * @var int
		 */
		private int $customer_id = 0;

		/**
		 * Order key, as WooCommerce generates for every order.
		 *
		 * @var string
		 */
		private string $order_key = '';

		/**
		 * Billing email address.
		 *
		 * @var string
		 */
		private string $billing_email = '';

		/**
		 * Value get_type() returns, e.g. `shop_order` or `shop_subscription`.
		 *
		 * @var string
		 */
		private string $type = 'shop_order';

		/**
		 * Payment gateway id.
		 *
		 * @var string
		 */
		private string $payment_method = '';

		/**
		 * Line items get_items() returns.
		 *
		 * @var array<int|string, object>
		 */
		private array $items = array();

		/**
		 * Order notes recorded by add_order_note(), in call order.
		 *
		 * @var list<array{note: string, is_customer_note: bool}>
		 */
		public array $notes = array();

		/**
		 * How many times update_status() has transitioned this order.
		 *
		 * @var int
		 */
		public int $status_transitions = 0;

		/**
		 * Customer-facing order number, when it differs from the id.
		 *
		 * @var string
		 */
		public string $order_number = '';

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
		 * Returns the customer user id, 0 for a guest order.
		 *
		 * @return int
		 */
		public function get_customer_id(): int {
			return $this->customer_id;
		}

		/**
		 * Returns the WordPress user this order belongs to, 0 for a guest.
		 *
		 * Core's own alias for the same value, carried here because a caller
		 * that wants the *user* — to read their locale, say — says so with this
		 * name rather than in the vocabulary of an access check.
		 *
		 * @return int
		 */
		public function get_user_id(): int {
			return $this->customer_id;
		}

		/**
		 * Sets the customer user id.
		 *
		 * @param int $customer_id Customer user id.
		 * @return void
		 */
		public function set_customer_id( int $customer_id ): void {
			$this->customer_id = $customer_id;
		}

		/**
		 * Returns the order key.
		 *
		 * @return string
		 */
		public function get_order_key(): string {
			return $this->order_key;
		}

		/**
		 * Sets the order key.
		 *
		 * @param string $order_key Order key.
		 * @return void
		 */
		public function set_order_key( string $order_key ): void {
			$this->order_key = $order_key;
		}

		/**
		 * Returns the billing email address.
		 *
		 * @return string
		 */
		public function get_billing_email(): string {
			return $this->billing_email;
		}

		/**
		 * Sets the billing email address.
		 *
		 * @param string $billing_email Billing email address.
		 * @return void
		 */
		public function set_billing_email( string $billing_email ): void {
			$this->billing_email = $billing_email;
		}

		/**
		 * Returns the order type, e.g. `shop_order` or `shop_subscription`.
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * Sets the order type.
		 *
		 * @param string $type Order type.
		 * @return void
		 */
		public function set_type( string $type ): void {
			$this->type = $type;
		}

		/**
		 * Returns the payment gateway id.
		 *
		 * @return string
		 */
		public function get_payment_method(): string {
			return $this->payment_method;
		}

		/**
		 * Sets the payment gateway id.
		 *
		 * @param string $payment_method Payment gateway id.
		 * @return void
		 */
		public function set_payment_method( string $payment_method ): void {
			$this->payment_method = $payment_method;
		}

		/**
		 * Returns the order's line items, keyed by item id as WooCommerce does.
		 *
		 * @return array<int|string, object>
		 */
		public function get_items(): array {
			return $this->items;
		}

		/**
		 * Sets the order's line items.
		 *
		 * @param array<int|string, object> $items Line items.
		 * @return void
		 */
		public function set_items( array $items ): void {
			$this->items = $items;
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
		 * Defaults to the id, as core does when no numbering plugin filters
		 * `woocommerce_order_number`. Settable so tests can build the store
		 * that matters most to guest lookup: one where the number a customer
		 * was given is not the id an attacker can count up to.
		 *
		 * @return string
		 */
		public function get_order_number(): string {
			return '' !== $this->order_number ? $this->order_number : (string) $this->id;
		}

		/**
		 * Sets a customer-facing order number distinct from the id.
		 *
		 * @param string $order_number Order number.
		 * @return void
		 */
		public function set_order_number( string $order_number ): void {
			$this->order_number = $order_number;
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

		/**
		 * Whether the order currently has the given status.
		 *
		 * @param string $status Unprefixed status.
		 * @return bool
		 */
		public function has_status( string $status ): bool {
			return $this->status === $status;
		}

		/**
		 * Transitions the order's status immediately, as WooCommerce's own
		 * update_status() does.
		 *
		 * @param string $new_status Status to change to.
		 * @param string $note       Note, unused by this stub.
		 * @param bool   $manual     Whether this is a manual change, unused by this stub.
		 * @return bool
		 */
		public function update_status( string $new_status, string $note = '', bool $manual = false ): bool {
			unset( $note, $manual );

			$this->status = $new_status;
			++$this->status_transitions;
			++$this->saves;

			return true;
		}

		/**
		 * Records an order note. Untyped params, matching WooCommerce's own
		 * loose signature: core callers pass `0`/`1` as often as `false`/`true`.
		 *
		 * @param string $note             Note text.
		 * @param mixed  $is_customer_note Whether the note is customer-visible.
		 * @param mixed  $added_by_user    Unused by this stub.
		 * @return int
		 */
		public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ): int {
			unset( $added_by_user );

			$this->notes[] = array(
				'note'             => (string) $note,
				'is_customer_note' => (bool) $is_customer_note,
			);

			return count( $this->notes );
		}

		/**
		 * Returns the order's admin edit URL.
		 *
		 * @return string
		 */
		public function get_edit_order_url(): string {
			return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
		}

		/**
		 * Returns the billing full name, formatted as WooCommerce would.
		 *
		 * @return string
		 */
		public function get_formatted_billing_full_name(): string {
			return '';
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
