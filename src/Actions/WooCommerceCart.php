<?php
/**
 * WooCommerce's own cart, behind the reorder action's seam.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Support\Logger;

/**
 * The only class in this plugin that touches `WC()->cart`.
 *
 * Two WooCommerce facts shape it. First, `WC_Cart` is not initialised on a
 * REST request at all: `WooCommerce::is_request( 'frontend' )` excludes the
 * REST API, so a controller that assumes `WC()->cart` gets null. Core's own
 * answer is `wc_load_cart()`, which is what the Store API's `CartController`
 * calls for exactly this reason, and what this class calls too — never
 * re-implementing session or cart setup, only asking for it.
 *
 * Second, adding goes through `WC_Cart::add_to_cart()` rather than writing
 * cart rows: that method is where WooCommerce validates stock against what is
 * already in the cart, applies `woocommerce_add_to_cart_validation`, and
 * merges a line the customer already had. Core's own `order_again` handler
 * bypasses all of it by writing the cart array directly — which is precisely
 * how it ends up holding a quantity the store cannot fulfil.
 *
 * @since 0.12.0
 */
final class WooCommerceCart implements CartGateway {

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param Logger $logger Records a cart that could not be loaded at all.
	 */
	public function __construct( private Logger $logger ) {}

	/**
	 * How many lines the cart currently holds.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function item_count(): int {
		$cart = $this->cart();

		return $cart instanceof \WC_Cart ? count( $cart->get_cart() ) : 0;
	}

	/**
	 * Empties the cart.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	public function clear(): void {
		$cart = $this->cart();

		if ( $cart instanceof \WC_Cart ) {
			$cart->empty_cart();
		}
	}

	/**
	 * Adds one planned line through WooCommerce's own add-to-cart path.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line to add.
	 * @return bool
	 */
	public function add( ReorderLine $line ): bool {
		$cart = $this->cart();

		if ( ! $cart instanceof \WC_Cart ) {
			return false;
		}

		try {
			$key = $cart->add_to_cart(
				$line->product_id,
				$line->quantity,
				$line->variation_id,
				$line->attributes,
				$this->item_data( $line )
			);
		} catch ( \Exception $e ) {
			// add_to_cart() throws for the cases it cannot describe as a
			// notice. Either way the answer to "did this line make it" is no,
			// and the customer is told which line by the outcome, not by an
			// exception page.
			$this->logger->warning(
				'Reorder line refused by the cart.',
				array(
					'product_id'   => $line->product_id,
					'variation_id' => $line->variation_id,
					'error'        => $e->getMessage(),
				)
			);

			return false;
		}

		return is_string( $key ) && '' !== $key;
	}

	/**
	 * The URL of the cart page.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public function url(): string {
		return function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';
	}

	/**
	 * Cart item data attached to every line this action adds.
	 *
	 * Empty by default, and filterable because a store with a per-line custom
	 * field (engraving, delivery date, a subscription add-on) is the store for
	 * which a reordered line needs to carry something core knows nothing
	 * about. Mirrors core's own `woocommerce_order_again_cart_item_data`
	 * without borrowing its hook, since a listener there is written against
	 * core's silent-skip behaviour, not this action's.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line being added.
	 * @return array<string, mixed>
	 */
	private function item_data( ReorderLine $line ): array {
		/**
		 * Filters the cart item data attached to a reordered line.
		 *
		 * @since 0.12.0
		 *
		 * @param array<string, mixed> $data Cart item data.
		 * @param ReorderLine          $line Line being added.
		 */
		$data = apply_filters( 'pph_reorder_cart_item_data', array(), $line );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The cart for this request, initialising it if this context has none.
	 *
	 * @since 0.12.0
	 * @return \WC_Cart|null
	 */
	private function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$cart = WC()->cart;

		if ( $cart instanceof \WC_Cart ) {
			return $cart;
		}

		// Guarded on the action wc_load_cart() itself requires: called any
		// earlier it only emits a _doing_it_wrong notice and returns.
		if ( ! function_exists( 'wc_load_cart' ) || ! did_action( 'woocommerce_init' ) ) {
			$this->logger->warning( 'Reorder ran in a context with no cart and no way to load one.' );

			return null;
		}

		wc_load_cart();

		$cart = WC()->cart;

		return $cart instanceof \WC_Cart ? $cart : null;
	}
}
