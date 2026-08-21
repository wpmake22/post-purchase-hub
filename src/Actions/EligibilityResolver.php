<?php
/**
 * Cross-cutting eligibility evaluation for actions.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Decides whether one action applies to one order, against a declarative rule.
 *
 * Every dimension a concrete action might care about — whether the merchant
 * offers the action at all, status, age, payment method, order type, product
 * type, per-order caps, cooldown — is evaluated here, once, so no action
 * re-implements this matrix slightly differently.
 * Checks run cheapest first: order type and status cost nothing, payment
 * method and age cost nothing, product type walks the order's line items, and
 * the request-history checks are the only ones that touch storage — so an
 * order that is disqualified on the first dimension never reaches the last.
 *
 * This class answers "does this order qualify", never "may this visitor act
 * on it" — that is `Security\OwnershipResolver`'s question, asked separately,
 * before this one.
 *
 * The cap and cooldown checks query stored request history by
 * `Requests\Request` type, not by the action id `resolve()` was called with:
 * an action's id (its identity in `ActionRegistry`, e.g. `cancel` — chosen to
 * collide with WooCommerce core's own list-action key) and the type its rows
 * are stored under (e.g. `Request::TYPE_CANCELLATION`) are not guaranteed to
 * be the same string. `EligibilityRule::$history_type` says which; when it is
 * null, the action id is used as a fallback that is only correct when the two
 * happen to match.
 *
 * @since 0.7.0
 */
final class EligibilityResolver {

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param RequestHistory $history Backing store for the cap and cooldown checks.
	 */
	public function __construct( private RequestHistory $history ) {}

	/**
	 * Evaluates one action's rule against one order.
	 *
	 * @since 0.7.0
	 *
	 * @param string          $action_id Action id, e.g. `cancel`. Used to scope the cap/cooldown history lookup and passed to the filter.
	 * @param \WC_Order       $order     Order to evaluate.
	 * @param EligibilityRule $rule     Rule to evaluate it against.
	 * @return EligibilityResult
	 */
	public function resolve( string $action_id, \WC_Order $order, EligibilityRule $rule ): EligibilityResult {
		$result = $this->evaluate( $action_id, $order, $rule );

		/**
		 * Filters an action's eligibility result for one order.
		 *
		 * @since 0.7.0
		 *
		 * @param EligibilityResult $result    Computed result.
		 * @param string            $action_id Action id.
		 * @param \WC_Order         $order     Order evaluated.
		 * @param EligibilityRule   $rule      Rule evaluated.
		 */
		$filtered = apply_filters( 'pph_action_eligibility', $result, $action_id, $order, $rule );

		return $filtered instanceof EligibilityResult ? $filtered : $result;
	}

	/**
	 * Runs the rule's dimensions in cost order and stops at the first failure.
	 *
	 * @since 0.7.0
	 *
	 * @param string          $action_id Action id.
	 * @param \WC_Order       $order     Order to evaluate.
	 * @param EligibilityRule $rule      Rule to evaluate it against.
	 * @return EligibilityResult
	 */
	private function evaluate( string $action_id, \WC_Order $order, EligibilityRule $rule ): EligibilityResult {
		// Asked first, and cheapest: a merchant who turned an action off has
		// said something about every order at once. Placed here rather than in
		// each action so the switch reaches the REST routes too — they re-check
		// through the same resolver, so an action that is off is refused rather
		// than merely undrawn.
		if ( ! ActionAvailability::is_enabled( $action_id ) ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_ACTION_DISABLED,
				__( 'This is not something you can do here.', 'post-purchase-hub' )
			);
		}

		if ( array() !== $rule->excluded_order_types && in_array( $order->get_type(), $rule->excluded_order_types, true ) ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_ORDER_TYPE_EXCLUDED,
				__( 'This order type is not eligible for this action.', 'post-purchase-hub' )
			);
		}

		if ( null !== $rule->allowed_statuses && ! in_array( $order->get_status(), $rule->allowed_statuses, true ) ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_STATUS_NOT_ELIGIBLE,
				__( 'This order is no longer in a status this action applies to.', 'post-purchase-hub' )
			);
		}

		if ( array() !== $rule->excluded_payment_methods && in_array( $order->get_payment_method(), $rule->excluded_payment_methods, true ) ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_PAYMENT_METHOD_EXCLUDED,
				__( 'This action is not available for the payment method used on this order.', 'post-purchase-hub' )
			);
		}

		$age_result = $this->check_age( $order, $rule );

		if ( null !== $age_result ) {
			return $age_result;
		}

		if ( array() !== $rule->excluded_product_types && $this->has_excluded_product( $order, $rule ) ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_PRODUCT_TYPE_EXCLUDED,
				__( 'This order contains a product type this action does not apply to.', 'post-purchase-hub' )
			);
		}

		$history_type = $rule->history_type ?? $action_id;

		if ( null !== $rule->per_order_cap && $this->history->count_for_order( $order->get_id(), $history_type ) >= $rule->per_order_cap ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_REQUEST_CAP_REACHED,
				__( 'This order has already reached the limit of requests for this action.', 'post-purchase-hub' )
			);
		}

		$cooldown_result = $this->check_cooldown( $history_type, $order, $rule );

		if ( null !== $cooldown_result ) {
			return $cooldown_result;
		}

		return EligibilityResult::allowed();
	}

	/**
	 * Checks the order's age against the rule's minimum and maximum, if any.
	 *
	 * An order with no recorded creation date — a state WooCommerce does not
	 * normally leave an order in — is treated as passing rather than failing:
	 * this is a data anomaly, not a reason to deny an otherwise-eligible action.
	 *
	 * @since 0.7.0
	 *
	 * @param \WC_Order       $order Order to evaluate.
	 * @param EligibilityRule $rule  Rule to evaluate it against.
	 * @return EligibilityResult|null Null when the age is within bounds (or unbounded/unknown).
	 */
	private function check_age( \WC_Order $order, EligibilityRule $rule ): ?EligibilityResult {
		if ( null === $rule->min_age_seconds && null === $rule->max_age_seconds ) {
			return null;
		}

		$created = $order->get_date_created();

		if ( ! $created instanceof \WC_DateTime ) {
			return null;
		}

		$age = time() - $created->getTimestamp();

		if ( null !== $rule->min_age_seconds && $age < $rule->min_age_seconds ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_ORDER_TOO_NEW,
				__( 'This order is too recent for this action yet.', 'post-purchase-hub' )
			);
		}

		if ( null !== $rule->max_age_seconds && $age > $rule->max_age_seconds ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_ORDER_TOO_OLD,
				__( 'This order is too old for this action now.', 'post-purchase-hub' )
			);
		}

		return null;
	}

	/**
	 * Whether any line item's product is one of the rule's excluded types.
	 *
	 * @since 0.7.0
	 *
	 * @param \WC_Order       $order Order to inspect.
	 * @param EligibilityRule $rule  Rule carrying the excluded product types.
	 * @return bool
	 */
	private function has_excluded_product( \WC_Order $order, EligibilityRule $rule ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( $product instanceof \WC_Product && in_array( $product->get_type(), $rule->excluded_product_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks the cooldown since the last request of this action's history type.
	 *
	 * @since 0.7.0
	 *
	 * @param string          $history_type Request type scoping the history lookup — see EligibilityRule::$history_type.
	 * @param \WC_Order       $order        Order to evaluate.
	 * @param EligibilityRule $rule         Rule carrying the cooldown length.
	 * @return EligibilityResult|null Null when no cooldown applies or it has elapsed.
	 */
	private function check_cooldown( string $history_type, \WC_Order $order, EligibilityRule $rule ): ?EligibilityResult {
		if ( null === $rule->cooldown_seconds ) {
			return null;
		}

		$last = $this->history->most_recent_for_order( $order->get_id(), $history_type );

		if ( null === $last ) {
			return null;
		}

		$created_at = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $last->created_at, new \DateTimeZone( 'UTC' ) );

		if ( ! $created_at instanceof \DateTimeImmutable ) {
			return null;
		}

		$elapsed = time() - $created_at->getTimestamp();

		if ( $elapsed < $rule->cooldown_seconds ) {
			return EligibilityResult::denied(
				EligibilityResult::REASON_COOLDOWN_ACTIVE,
				__( 'Please wait before requesting this action again.', 'post-purchase-hub' )
			);
		}

		return null;
	}
}
