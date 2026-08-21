<?php
/**
 * One line of a reorder reconciliation summary.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * What will happen to one historical order line if the customer confirms.
 *
 * Pure data, computed by `ReorderPlanner` and read by both the summary view
 * and `Reorder::execute()`. Every outcome is explicit: docs/SPEC.md Phase 4
 * risk "Reorder surprises" makes silent partial cart adds the one failure this
 * feature is not allowed to have, so a line that will not be added says which
 * of the four reasons applies, and a line that will be added at a different
 * price or a different quantity says that too.
 *
 * `attributes` is the only field that is not display data: it carries the
 * variation attributes `WC_Cart::add_to_cart()` needs, so the executor never
 * re-derives from the order what the planner already resolved.
 *
 * @since 0.12.0
 */
final class ReorderLine {

	/**
	 * The full quantity will be added.
	 *
	 * @var string
	 */
	public const OUTCOME_ADDED = 'added';

	/**
	 * Product is gone, unpublished or no longer purchasable.
	 *
	 * @var string
	 */
	public const OUTCOME_UNAVAILABLE = 'unavailable';

	/**
	 * Product exists but nothing can be bought right now.
	 *
	 * @var string
	 */
	public const OUTCOME_OUT_OF_STOCK = 'out_of_stock';

	/**
	 * Product exists but not in the quantity originally bought.
	 *
	 * @var string
	 */
	public const OUTCOME_QUANTITY_REDUCED = 'quantity_reduced';

	/**
	 * The exact variation bought is no longer resolvable; the parent product is.
	 *
	 * @var string
	 */
	public const OUTCOME_VARIATION_CHANGED = 'variation_changed';

	/**
	 * The line fell beyond this attempt's item cap and was never validated.
	 *
	 * @var string
	 */
	public const OUTCOME_NOT_CHECKED = 'not_checked';

	/**
	 * Smallest price movement reported as a change, in the order's currency.
	 *
	 * Half a minor unit: below this a "price changed" line would show a
	 * formatted delta of zero, which reads as a bug rather than as honesty.
	 *
	 * @var float
	 */
	public const PRICE_TOLERANCE = 0.005;

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param string                $outcome            One of the OUTCOME_* constants.
	 * @param string                $name               Line item name as it was bought.
	 * @param int                   $product_id         Parent product id, 0 when unresolvable.
	 * @param int                   $variation_id       Variation id, 0 for a non-variable line.
	 * @param int                   $requested_quantity Quantity on the historical order.
	 * @param int                   $quantity           Quantity that will actually be added, 0 when none.
	 * @param float|null            $original_price     Per-unit price paid, excluding tax. Null when not comparable.
	 * @param float|null            $current_price      Per-unit price now, excluding tax. Null when not comparable.
	 * @param string                $url                Product page to send the customer to when they must re-choose.
	 * @param array<string, string> $attributes         Variation attributes for the cart, keyed `attribute_*`.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $name,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly int $requested_quantity,
		public readonly int $quantity,
		public readonly ?float $original_price,
		public readonly ?float $current_price,
		public readonly string $url = '',
		public readonly array $attributes = array()
	) {}

	/**
	 * Whether confirming will put this line in the cart.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function is_addable(): bool {
		return $this->quantity > 0
			&& in_array( $this->outcome, array( self::OUTCOME_ADDED, self::OUTCOME_QUANTITY_REDUCED ), true );
	}

	/**
	 * Whether the per-unit price has moved since the order was placed.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function price_changed(): bool {
		$delta = $this->price_delta();

		return null !== $delta && abs( $delta ) >= self::PRICE_TOLERANCE;
	}

	/**
	 * The per-unit price movement, positive when it costs more now.
	 *
	 * @since 0.12.0
	 * @return float|null Null when the two prices are not comparable.
	 */
	public function price_delta(): ?float {
		if ( null === $this->original_price || null === $this->current_price ) {
			return null;
		}

		return $this->current_price - $this->original_price;
	}
}
