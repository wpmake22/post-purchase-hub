<?php
/**
 * Cart double for the unit suite.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PostPurchaseHub\Actions\CartGateway;
use PostPurchaseHub\Actions\ReorderLine;

/**
 * Records what the reorder action would do to a cart, and can refuse a line.
 *
 * The refusal list exists because the interesting case is not the happy one:
 * stock can go between the summary being drawn and the customer confirming, and
 * the outcome has to say so rather than quietly reporting a success.
 *
 * @since 0.12.0
 */
final class FakeCart implements CartGateway {

	/**
	 * Lines added, in call order.
	 *
	 * @var list<ReorderLine>
	 */
	public array $added = array();

	/**
	 * How many times clear() was called.
	 *
	 * @var int
	 */
	public int $clears = 0;

	/**
	 * Lines already in the cart, as a count.
	 *
	 * @var int
	 */
	public int $existing = 0;

	/**
	 * Product ids add() should refuse.
	 *
	 * @var list<int>
	 */
	public array $refuse = array();

	/**
	 * How many lines the cart holds.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function item_count(): int {
		return $this->existing + count( $this->added );
	}

	/**
	 * Empties the cart.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	public function clear(): void {
		++$this->clears;
		$this->existing = 0;
		$this->added    = array();
	}

	/**
	 * Adds a line unless it is on the refusal list.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line to add.
	 * @return bool
	 */
	public function add( ReorderLine $line ): bool {
		if ( in_array( $line->product_id, $this->refuse, true ) ) {
			return false;
		}

		$this->added[] = $line;

		return true;
	}

	/**
	 * The cart URL.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public function url(): string {
		return 'https://example.test/cart/';
	}

	/**
	 * Whether the cart was touched at all.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	public function untouched(): bool {
		return 0 === $this->clears && array() === $this->added;
	}
}
