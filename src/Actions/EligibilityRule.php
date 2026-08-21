<?php
/**
 * Declarative eligibility rule for one action.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * What has to be true about an order for one action to apply to it.
 *
 * Every dimension is independently optional: a concrete action states only the
 * constraints it actually has, rather than one action's silence about a
 * dimension being mistaken for another action's restriction. `null` (or an
 * empty exclusion list) means "this rule does not constrain that dimension" —
 * never "nothing is allowed".
 *
 * Pure data. `EligibilityResolver` is what reads it; this class validates
 * nothing about the order it will later be checked against.
 *
 * @since 0.7.0
 */
final class EligibilityRule {

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param string[]|null $allowed_statuses         Unprefixed order statuses the action applies to. Null: no status restriction.
	 * @param int|null      $min_age_seconds          Minimum seconds since `date_created` before the action is eligible. Null: no minimum.
	 * @param int|null      $max_age_seconds          Maximum seconds since `date_created` the action stays eligible for. Null: no maximum.
	 * @param string[]      $excluded_payment_methods Payment gateway ids the action is never eligible for.
	 * @param string[]      $excluded_order_types     Values of `WC_Order::get_type()` the action is never eligible for.
	 * @param string[]      $excluded_product_types   Product types that, present on any line item, exclude the whole order.
	 * @param int|null      $per_order_cap            Maximum number of requests an order may accumulate under `$history_type`, ever. Null: no cap.
	 * @param int|null      $cooldown_seconds         Minimum seconds since the last request under `$history_type` on this order. Null: no cooldown.
	 * @param string|null   $history_type             `Requests\Request` type the cap and cooldown checks query against, e.g. `Request::TYPE_CANCELLATION`. Null: use the action id passed to `resolve()` — correct only when an action's id and its stored request type happen to be the same string.
	 */
	public function __construct(
		public readonly ?array $allowed_statuses = null,
		public readonly ?int $min_age_seconds = null,
		public readonly ?int $max_age_seconds = null,
		public readonly array $excluded_payment_methods = array(),
		public readonly array $excluded_order_types = array(),
		public readonly array $excluded_product_types = array(),
		public readonly ?int $per_order_cap = null,
		public readonly ?int $cooldown_seconds = null,
		public readonly ?string $history_type = null
	) {}
}
