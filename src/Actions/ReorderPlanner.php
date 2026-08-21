<?php
/**
 * Line-by-line validation behind the reorder reconciliation summary.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Turns a historical order into an explicit statement of what can be bought
 * again, without touching a cart.
 *
 * Split out of `Reorder` because this is the only genuinely intricate part of
 * the feature and it deserves to be tested on its own: five outcomes, each
 * with a WooCommerce fact behind it.
 *
 * - A deleted product and a deleted *variation* are different failures.
 *   `WC_Order_Item_Product::get_product()` returns false for both, so the
 *   parent is looked up separately: a live parent means the customer can
 *   re-choose, and gets a link rather than a dead end.
 * - Stock is read through `WC_Product::get_max_purchase_quantity()` rather
 *   than `is_in_stock()`, because that one call already folds in
 *   sold-individually, backorders and managed stock — the reason core's
 *   `order_again` can put an unfulfillable quantity in the cart is that it
 *   asks only the boolean.
 * - Prices are compared per unit and excluding tax on both sides: the line's
 *   own `get_subtotal()` (pre-discount, ex-tax, in the order's currency)
 *   against `wc_get_price_excluding_tax()` now, so a tax-inclusive store does
 *   not report a phantom delta on every line.
 * - A line in a currency the store no longer sells in reports no delta at all
 *   rather than a converted-looking number that is really two currencies
 *   subtracted.
 *
 * Work is bounded by an item cap (docs/SPEC.md Phase 4): lines past it are
 * reported as unchecked instead of quietly dropped, since a 400-line wholesale
 * order must not turn one click into 400 product loads.
 *
 * @since 0.12.0
 */
final class ReorderPlanner {

	/**
	 * Builds the plan for one order.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order    Order to reorder from.
	 * @param int       $item_cap Maximum lines to validate.
	 * @return ReorderPlan
	 */
	public function plan( \WC_Order $order, int $item_cap ): ReorderPlan {
		$cap        = max( 1, $item_cap );
		$lines      = array();
		$checked    = 0;
		$comparable = $this->prices_comparable( $order );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( $checked >= $cap ) {
				$lines[] = $this->unchecked_line( $item );
				continue;
			}

			++$checked;
			$lines[] = $this->line( $item, $comparable );
		}

		return new ReorderPlan( $order->get_id(), $lines, $cap );
	}

	/**
	 * Evaluates one line item.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order_Item_Product $item       Line item.
	 * @param bool                   $comparable Whether prices may be compared for this order.
	 * @return ReorderLine
	 */
	private function line( \WC_Order_Item_Product $item, bool $comparable ): ReorderLine {
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		$quantity     = max( 1, (int) $item->get_quantity() );
		$name         = (string) $item->get_name();
		$product      = $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			return $this->missing_line( $name, $product_id, $variation_id, $quantity );
		}

		if ( $variation_id > 0 && $product_id > 0 && (int) $product->get_parent_id() !== $product_id ) {
			// The variation resolved, but no longer to the product that was
			// bought — a re-parented or rebuilt variation. Treated as a
			// changed variation, not as a silent substitution.
			return $this->line_with(
				ReorderLine::OUTCOME_VARIATION_CHANGED,
				$name,
				$product_id,
				$variation_id,
				$quantity,
				0,
				$this->parent_url( $product_id )
			);
		}

		if ( 0 === $variation_id && $product->is_type( 'variable' ) ) {
			// The line records a variable parent with no variation of its own —
			// an order WooCommerce itself refuses to reorder, since there is
			// nothing to put in a cart. Core skips it silently; here the
			// customer is sent to the product to choose again.
			return $this->line_with(
				ReorderLine::OUTCOME_VARIATION_CHANGED,
				$name,
				$product_id,
				$variation_id,
				$quantity,
				0,
				(string) $product->get_permalink()
			);
		}

		if ( ! $product->is_purchasable() ) {
			return $this->line_with( ReorderLine::OUTCOME_UNAVAILABLE, $name, $product_id, $variation_id, $quantity, 0 );
		}

		$buyable = $this->buyable_quantity( $product, $quantity );

		if ( 0 === $buyable ) {
			return $this->line_with( ReorderLine::OUTCOME_OUT_OF_STOCK, $name, $product_id, $variation_id, $quantity, 0 );
		}

		$outcome = $buyable < $quantity ? ReorderLine::OUTCOME_QUANTITY_REDUCED : ReorderLine::OUTCOME_ADDED;

		return new ReorderLine(
			$outcome,
			$name,
			$product_id,
			$variation_id,
			$quantity,
			$buyable,
			$comparable ? $this->original_price( $item, $quantity ) : null,
			$comparable ? $this->current_price( $product ) : null,
			(string) $product->get_permalink(),
			$this->variation_attributes( $item )
		);
	}

	/**
	 * Distinguishes a deleted product from a deleted variation.
	 *
	 * @since 0.12.0
	 *
	 * @param string $name         Line item name.
	 * @param int    $product_id   Parent product id.
	 * @param int    $variation_id Variation id.
	 * @param int    $quantity     Quantity originally bought.
	 * @return ReorderLine
	 */
	private function missing_line( string $name, int $product_id, int $variation_id, int $quantity ): ReorderLine {
		$parent_url = $variation_id > 0 ? $this->parent_url( $product_id ) : '';

		if ( '' !== $parent_url ) {
			return $this->line_with( ReorderLine::OUTCOME_VARIATION_CHANGED, $name, $product_id, $variation_id, $quantity, 0, $parent_url );
		}

		return $this->line_with( ReorderLine::OUTCOME_UNAVAILABLE, $name, $product_id, $variation_id, $quantity, 0 );
	}

	/**
	 * The product page a customer whose variation is gone should be sent to.
	 *
	 * @since 0.12.0
	 *
	 * @param int $product_id Parent product id.
	 * @return string Empty when the parent product is gone too.
	 */
	private function parent_url( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		$parent = wc_get_product( $product_id );

		return $parent instanceof \WC_Product ? (string) $parent->get_permalink() : '';
	}

	/**
	 * A line the item cap stopped this attempt from validating.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return ReorderLine
	 */
	private function unchecked_line( \WC_Order_Item_Product $item ): ReorderLine {
		return $this->line_with(
			ReorderLine::OUTCOME_NOT_CHECKED,
			(string) $item->get_name(),
			(int) $item->get_product_id(),
			(int) $item->get_variation_id(),
			max( 1, (int) $item->get_quantity() ),
			0
		);
	}

	/**
	 * Builds a line that carries no price comparison.
	 *
	 * @since 0.12.0
	 *
	 * @param string $outcome      One of the ReorderLine::OUTCOME_* constants.
	 * @param string $name         Line item name.
	 * @param int    $product_id   Parent product id.
	 * @param int    $variation_id Variation id.
	 * @param int    $requested    Quantity originally bought.
	 * @param int    $quantity     Quantity that will be added.
	 * @param string $url          Product page to send the customer to.
	 * @return ReorderLine
	 */
	private function line_with( string $outcome, string $name, int $product_id, int $variation_id, int $requested, int $quantity, string $url = '' ): ReorderLine {
		return new ReorderLine( $outcome, $name, $product_id, $variation_id, $requested, $quantity, null, null, $url );
	}

	/**
	 * How many units of a product may be bought right now, capped at the
	 * quantity originally ordered.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Product $product  Product to check.
	 * @param int         $quantity Quantity originally ordered.
	 * @return int Zero when nothing can be bought.
	 */
	private function buyable_quantity( \WC_Product $product, int $quantity ): int {
		$max = (int) $product->get_max_purchase_quantity();

		if ( 0 === $max ) {
			return 0;
		}

		if ( $max < 0 ) {
			return $quantity;
		}

		return min( $quantity, $max );
	}

	/**
	 * The per-unit price paid, excluding tax.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order_Item_Product $item     Line item.
	 * @param int                    $quantity Quantity originally bought.
	 * @return float
	 */
	private function original_price( \WC_Order_Item_Product $item, int $quantity ): float {
		return (float) $item->get_subtotal() / max( 1, $quantity );
	}

	/**
	 * The per-unit price now, excluding tax.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Product $product Product to price.
	 * @return float|null Null when WooCommerce cannot price it.
	 */
	private function current_price( \WC_Product $product ): ?float {
		if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
			return null;
		}

		$price = wc_get_price_excluding_tax( $product, array( 'qty' => 1 ) );

		return is_numeric( $price ) ? (float) $price : null;
	}

	/**
	 * Whether this order's prices may be compared against today's catalogue.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to check.
	 * @return bool
	 */
	private function prices_comparable( \WC_Order $order ): bool {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return false;
		}

		$order_currency = (string) $order->get_currency();
		$store_currency = (string) get_woocommerce_currency();

		return '' === $order_currency || $store_currency === $order_currency;
	}

	/**
	 * The variation attributes to hand `WC_Cart::add_to_cart()`.
	 *
	 * Read from the line item's own meta, the way core reads them, because a
	 * variation with an `any` attribute has no stored value of its own and the
	 * cart refuses the line without one. Deliberately the same two branches
	 * core uses: a taxonomy attribute's value is a slug, a custom attribute's
	 * is a label that has to survive entity encoding.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return array<string, string>
	 */
	private function variation_attributes( \WC_Order_Item_Product $item ): array {
		if ( 0 === (int) $item->get_variation_id() ) {
			return array();
		}

		$attributes = array();

		foreach ( $item->get_meta_data() as $meta ) {
			$key   = isset( $meta->key ) ? (string) $meta->key : '';
			$value = isset( $meta->value ) && is_scalar( $meta->value ) ? (string) $meta->value : '';

			if ( '' === $key ) {
				continue;
			}

			if ( taxonomy_is_product_attribute( $key ) ) {
				$attributes[ 'attribute_' . sanitize_title( $key ) ] = sanitize_title( $value );
				continue;
			}

			if ( meta_is_product_attribute( $key, $value, (int) $item->get_product_id() ) ) {
				$attributes[ 'attribute_' . sanitize_title( $key ) ] = html_entity_decode(
					wc_clean( $value ),
					ENT_QUOTES,
					get_bloginfo( 'charset' )
				);
			}
		}

		return $attributes;
	}
}
