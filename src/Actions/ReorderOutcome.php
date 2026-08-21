<?php
/**
 * What actually happened when a reorder was confirmed.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * The executed result, as distinct from the plan that predicted it.
 *
 * They can differ, and the type exists so that difference is reportable
 * rather than silent: stock can sell out, or a merchant's own
 * `woocommerce_add_to_cart_validation` filter can refuse a line, between the
 * moment the summary was drawn and the moment the customer confirmed. Lines
 * the cart refused arrive here as `rejected` instead of being dropped.
 *
 * @since 0.12.0
 */
final class ReorderOutcome {

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param string $mode     Mode the cart was updated under, one of Reorder::MODES.
	 * @param array  $added    Lines the cart accepted.
	 * @param array  $rejected Lines the cart refused at add time.
	 *
	 * @phpstan-param list<ReorderLine> $added
	 * @phpstan-param list<ReorderLine> $rejected
	 */
	public function __construct(
		public readonly string $mode,
		public readonly array $added,
		public readonly array $rejected
	) {}

	/**
	 * How many lines reached the cart.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function added_count(): int {
		return count( $this->added );
	}

	/**
	 * How many units reached the cart.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function quantity_added(): int {
		$total = 0;

		foreach ( $this->added as $line ) {
			$total += $line->quantity;
		}

		return $total;
	}
}
