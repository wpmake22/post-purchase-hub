<?php
/**
 * The cart, as the reorder action needs it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * The four things this plugin ever asks of a cart.
 *
 * A seam, for the same reason `Integrations\Tracking\TrackingAvailability` is
 * one: the unit suite boots no WooCommerce, and a cart is the one dependency
 * here that cannot be a value object. It also keeps every `WC()->cart` call in
 * the codebase inside a single implementation, so the "is the cart even loaded
 * on this request" question is answered once — see `WooCommerceCart`.
 *
 * @since 0.12.0
 */
interface CartGateway {

	/**
	 * How many lines the cart currently holds.
	 *
	 * The number the merge-or-replace choice is offered on, so a cart that
	 * cannot be loaded reports 0 rather than throwing.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function item_count(): int;

	/**
	 * Empties the cart.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	public function clear(): void;

	/**
	 * Adds one planned line to the cart.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line to add. Implementations may assume `is_addable()`.
	 * @return bool True when the cart accepted it.
	 */
	public function add( ReorderLine $line ): bool;

	/**
	 * The URL of the cart page.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public function url(): string;
}
