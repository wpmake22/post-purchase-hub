<?php
/**
 * The reconciliation plan for one reorder attempt.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Every line of one order, each with the outcome it would have, and nothing
 * else — building this plan mutates no cart.
 *
 * The whole point of the type is that the same object answers the summary
 * screen's question ("what will happen") and the executor's question ("what do
 * I add"), from one evaluation. A second evaluation between showing and
 * confirming is deliberate — `Reorder::execute()` re-plans rather than trusting
 * a plan round-tripped through the client — but both are this same shape.
 *
 * @since 0.12.0
 */
final class ReorderPlan {

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param int   $order_id Order the plan was built from.
	 * @param array $lines    Every line of the order, in order.
	 * @param int   $item_cap Item cap this plan was built under.
	 *
	 * @phpstan-param list<ReorderLine> $lines
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly array $lines,
		public readonly int $item_cap
	) {}

	/**
	 * The lines that will reach the cart.
	 *
	 * @since 0.12.0
	 *
	 * @return list<ReorderLine>
	 */
	public function addable(): array {
		return array_values(
			array_filter(
				$this->lines,
				static function ( ReorderLine $line ): bool {
					return $line->is_addable();
				}
			)
		);
	}

	/**
	 * Whether anything at all will reach the cart.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function has_addable(): bool {
		return array() !== $this->addable();
	}

	/**
	 * Whether the order had lines but none of them can be bought again.
	 *
	 * The state docs/SPEC.md requires a clear message for, with the cart left
	 * untouched — distinct from an order with no line items at all, which is a
	 * data anomaly rather than something to explain to a customer.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function nothing_available(): bool {
		return array() !== $this->lines && ! $this->has_addable();
	}

	/**
	 * Whether the order had no line items to reorder.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->lines;
	}

	/**
	 * Whether the item cap stopped this plan short of validating every line.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function was_capped(): bool {
		foreach ( $this->lines as $line ) {
			if ( ReorderLine::OUTCOME_NOT_CHECKED === $line->outcome ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Total number of units that will reach the cart.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function quantity(): int {
		$total = 0;

		foreach ( $this->addable() as $line ) {
			$total += $line->quantity;
		}

		return $total;
	}
}
