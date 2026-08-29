<?php
/**
 * Default order/product type exclusions for actions with dangerous semantics.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Compat;

/**
 * The order and product types cancel and reorder hard-exclude by default.
 *
 * Docs/SPEC.md risk T5: a subscription's parent or renewal order, and an
 * order containing a bookable product, both have cancel/reorder semantics
 * this plugin does not attempt to reason about in v1.
 *
 * Detection is deliberately keyed on WooCommerce Subscriptions' and
 * Bookings' own stable, publicly documented type-registration slugs —
 * `shop_subscription` as an order type, `subscription` / `variable-subscription`
 * / `booking` as product types — rather than on either plugin's internal
 * functions or meta shape. A subscription's own record carries the order
 * type; both its parent (initial purchase) order and every renewal order
 * carry a subscription product on their line items; so the same product-type
 * check that excludes a booking also, without any Subscriptions-specific
 * detection, excludes both. Neither extension needs to be installed for this
 * to be correct — a type slug that plugin never registers simply never
 * matches — but this has not been exercised against a live installation of
 * either, since neither is available in this environment.
 *
 * These are inputs to an EligibilityRule, not eligibility checks themselves:
 * this class hooks nothing. Whichever action wants the exclusion merges
 * these into its own rule's `excluded_order_types` / `excluded_product_types`.
 *
 * @since 0.7.0
 */
final class HardExclusions {

	/**
	 * The order types cancel and reorder exclude by default.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	public static function order_types(): array {
		/**
		 * Filters the order types hard-excluded from cancel and reorder.
		 *
		 * Excluded by default; returning an empty array opts out entirely, per
		 * docs/SPEC.md risk T5's "filterable" requirement.
		 *
		 * @since 0.7.0
		 *
		 * @param string[] $order_types Excluded `WC_Order::get_type()` values.
		 */
		return (array) apply_filters( 'wpmphub_compat_excluded_order_types', array( 'shop_subscription' ) );
	}

	/**
	 * The product types cancel and reorder exclude by default.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	public static function product_types(): array {
		/**
		 * Filters the product types hard-excluded from cancel and reorder.
		 *
		 * Excluded by default; returning an empty array opts out entirely, per
		 * docs/SPEC.md risk T5's "filterable" requirement.
		 *
		 * @since 0.7.0
		 *
		 * @param string[] $product_types Excluded product type slugs.
		 */
		return (array) apply_filters(
			'wpmphub_compat_excluded_product_types',
			array( 'subscription', 'variable-subscription', 'booking' )
		);
	}
}
