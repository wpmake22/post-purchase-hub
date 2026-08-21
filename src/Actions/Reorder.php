<?php
/**
 * The reorder action.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Integrations\Compat\HardExclusions;

/**
 * Buying a past order again, with the surprises stated up front.
 *
 * WooCommerce already has this feature; what it does not have is honesty about
 * it. Core's `order_again` empties the cart, writes whatever lines still
 * resolve straight into it, silently drops the rest, and mutates the cart on a
 * GET. This action keeps core's semantics — the same eligible statuses, read
 * from core's own `woocommerce_valid_order_statuses_for_order_again` filter,
 * and the same cart API underneath (`WooCommerceCart`) — and changes only the
 * three things that make it trustworthy:
 *
 * 1. Nothing is mutated until the customer has seen the reconciliation summary
 *    and confirmed it, over POST (CLAUDE.md hard rule 4).
 * 2. A line that cannot be bought again says which of the four reasons applies
 *    (`ReorderPlanner`), rather than vanishing.
 * 3. A non-empty cart is merged into by default rather than emptied, and the
 *    customer is offered the choice either way.
 *
 * Ownership is not decided here — `Security\OwnershipResolver` decides it,
 * once, before any caller reaches this class. What is decided here is whether
 * the *order* qualifies, plus the one visitor-shaped condition core also
 * imposes: reorder needs an account, because a cart belongs to a session and
 * v1 does not open a cart-bearing session for a token-bearing guest.
 *
 * @since 0.12.0
 */
final class Reorder {

	/**
	 * Action id.
	 *
	 * @var string
	 */
	public const ID = 'reorder';

	/**
	 * Query argument carrying the order whose summary is being viewed.
	 *
	 * @var string
	 */
	public const QUERY_ARG = 'pph_reorder';

	/**
	 * Add to whatever is already in the cart.
	 *
	 * @var string
	 */
	public const MODE_MERGE = 'merge';

	/**
	 * Empty the cart first.
	 *
	 * @var string
	 */
	public const MODE_REPLACE = 'replace';

	/**
	 * Lines validated per attempt when nothing overrides it.
	 *
	 * Bounded because docs/SPEC.md Phase 4 requires it: each validated line
	 * costs a product load, and a wholesale order can carry hundreds.
	 *
	 * @var int
	 */
	public const DEFAULT_ITEM_CAP = 50;

	/**
	 * Denial code for a visitor with no account.
	 *
	 * @var string
	 */
	public const REASON_LOGIN_REQUIRED = 'login_required';

	/**
	 * Denial code for an order where every line is unavailable.
	 *
	 * @var string
	 */
	public const REASON_NOTHING_AVAILABLE = 'nothing_available';

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param EligibilityResolver $eligibility Eligibility engine.
	 * @param ReorderPlanner      $planner     Builds the reconciliation plan.
	 * @param CartGateway         $cart        The cart, only ever written by execute().
	 */
	public function __construct(
		private EligibilityResolver $eligibility,
		private ReorderPlanner $planner,
		private CartGateway $cart
	) {}

	/**
	 * Registers this action against the registry.
	 *
	 * @since 0.12.0
	 *
	 * @param ActionRegistry $registry Registry to register against.
	 * @return void
	 */
	public function register( ActionRegistry $registry ): void {
		$registry->register(
			self::ID,
			self::label(),
			array( 'list', 'detail' ),
			\Closure::fromCallable( array( $this, 'resolve' ) )
		);
	}

	/**
	 * Render payload for one order and context, or null when ineligible.
	 *
	 * The URL is a plain link to the order's own page carrying the summary
	 * query argument, so the reconciliation screen is reachable, bookmarkable
	 * and rendered server-side. Only the confirmation needs JavaScript.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order   Order to resolve against.
	 * @param string    $context Context being rendered, unused: the action reads the same either way.
	 * @return array<string, string>|null
	 */
	public function resolve( \WC_Order $order, string $context ): ?array {
		unset( $context );

		if ( ! $this->check( $order )->eligible ) {
			return null;
		}

		return array(
			'name' => self::label(),
			'url'  => self::summary_url( $order ),
		);
	}

	/**
	 * Whether this order may be reordered by whoever is asking.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function check( \WC_Order $order ): EligibilityResult {
		if ( ! is_user_logged_in() ) {
			return EligibilityResult::denied(
				self::REASON_LOGIN_REQUIRED,
				__( 'Sign in to your account to buy these items again.', 'post-purchase-hub' )
			);
		}

		return $this->eligibility->resolve( self::ID, $order, $this->rule() );
	}

	/**
	 * The reconciliation plan for an order. Mutates nothing.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to plan from.
	 * @return ReorderPlan
	 */
	public function preview( \WC_Order $order ): ReorderPlan {
		return $this->planner->plan( $order, self::item_cap() );
	}

	/**
	 * How many lines the cart currently holds, for the merge-or-replace choice.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public function cart_item_count(): int {
		return $this->cart->item_count();
	}

	/**
	 * The URL of the cart page.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public function cart_url(): string {
		return $this->cart->url();
	}

	/**
	 * Re-checks eligibility, re-plans, and updates the cart if both still hold.
	 *
	 * The plan is rebuilt here rather than accepted from the caller: a summary
	 * the customer read a minute ago is evidence of what they agreed to, never
	 * evidence of what the catalogue currently allows.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to reorder from.
	 * @param string    $mode  One of MODE_MERGE or MODE_REPLACE.
	 * @return ReorderOutcome
	 * @throws IneligibleActionException When the order is not eligible, or nothing on it can be bought.
	 */
	public function execute( \WC_Order $order, string $mode ): ReorderOutcome {
		$result = $this->check( $order );

		if ( ! $result->eligible ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor arg stored as a property, not the message; IneligibleActionException escapes its own message.
			throw new IneligibleActionException( $result );
		}

		$plan = $this->preview( $order );

		if ( ! $plan->has_addable() ) {
			// Thrown before the cart is touched, so "nothing is available"
			// never costs the customer the cart they already had.
			$denied = EligibilityResult::denied(
				self::REASON_NOTHING_AVAILABLE,
				__( 'None of the items on this order can be bought again right now. Your cart has not been changed.', 'post-purchase-hub' )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor arg stored as a property, not the message; IneligibleActionException escapes its own message.
			throw new IneligibleActionException( $denied );
		}

		$mode = self::normalise_mode( $mode );

		if ( self::MODE_REPLACE === $mode ) {
			$this->cart->clear();
		}

		$added    = array();
		$rejected = array();

		foreach ( $plan->addable() as $line ) {
			if ( $this->cart->add( $line ) ) {
				$added[] = $line;
				continue;
			}

			$rejected[] = $line;
		}

		$outcome = new ReorderOutcome( $mode, $added, $rejected );

		/**
		 * Fires once a reorder has updated the cart.
		 *
		 * @since 0.12.0
		 *
		 * @param \WC_Order      $order   Order that was reordered.
		 * @param ReorderOutcome $outcome What reached the cart, and what did not.
		 */
		do_action( 'pph_reorder_completed', $order, $outcome );

		return $outcome;
	}

	/**
	 * The translated action label.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public static function label(): string {
		return __( 'Buy these again', 'post-purchase-hub' );
	}

	/**
	 * The URL of an order's reconciliation summary.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	public static function summary_url( \WC_Order $order ): string {
		return (string) add_query_arg( self::QUERY_ARG, (string) $order->get_id(), $order->get_view_order_url() );
	}

	/**
	 * The modes a cart may be updated under.
	 *
	 * @since 0.12.0
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_MERGE, self::MODE_REPLACE );
	}

	/**
	 * The mode used when the customer expresses no preference.
	 *
	 * Merge, unlike core's `order_again`, which empties the cart
	 * unconditionally. A customer who was mid-shop when they clicked this did
	 * not ask to lose that, and the alternative is offered in the same screen
	 * — but a store selling configured or single-item baskets may reasonably
	 * disagree, hence the filter.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public static function default_mode(): string {
		/**
		 * Filters the cart mode a reorder uses when the customer picks neither.
		 *
		 * @since 0.12.0
		 *
		 * @param string $mode One of Reorder::modes().
		 */
		return self::normalise_mode( (string) apply_filters( 'pph_reorder_default_mode', self::MODE_MERGE ) );
	}

	/**
	 * Coerces a candidate mode to one this action accepts.
	 *
	 * @since 0.12.0
	 *
	 * @param string $mode Candidate mode.
	 * @return string
	 */
	public static function normalise_mode( string $mode ): string {
		return in_array( $mode, array( self::MODE_MERGE, self::MODE_REPLACE ), true ) ? $mode : self::MODE_MERGE;
	}

	/**
	 * How many lines one attempt validates.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public static function item_cap(): int {
		/**
		 * Filters the number of order lines one reorder attempt validates.
		 *
		 * @since 0.12.0
		 *
		 * @param int $cap Maximum lines per attempt.
		 */
		$cap = (int) apply_filters( 'pph_reorder_item_cap', self::DEFAULT_ITEM_CAP );

		return max( 1, $cap );
	}

	/**
	 * The order statuses reorder applies to.
	 *
	 * Core's own filter is read first, so a store that has already widened
	 * `order_again` does not have to widen this separately and cannot end up
	 * with the two disagreeing. The second filter exists for the opposite
	 * case: widening this plugin's reconciled reorder without also changing
	 * core's unreconciled one everywhere else.
	 *
	 * @since 0.12.0
	 *
	 * @return string[]
	 */
	public static function allowed_statuses(): array {
		/** This filter is documented in WooCommerce: includes/wc-template-functions.php */
		$statuses = (array) apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately reads WooCommerce's own filter rather than declaring one, so this action and core agree on which statuses reorder applies to.

		/**
		 * Filters the order statuses this plugin's reorder action applies to.
		 *
		 * @since 0.12.0
		 *
		 * @param string[] $statuses Unprefixed order statuses.
		 */
		$statuses = (array) apply_filters( 'pph_reorder_allowed_statuses', array_values( $statuses ) );

		$clean = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && '' !== $status ) {
				$clean[] = str_replace( 'wc-', '', $status );
			}
		}

		return array() !== $clean ? $clean : array( 'completed' );
	}

	/**
	 * This action's eligibility rule.
	 *
	 * No per-order cap and no cooldown: reorder stores nothing, so there is no
	 * request history to count. Abuse of the endpoint is bounded by the rate
	 * limiter in `Rest\ReorderController` and by the item cap, which is where
	 * a cart-filling attempt actually costs something.
	 *
	 * @since 0.12.0
	 *
	 * @return EligibilityRule
	 */
	private function rule(): EligibilityRule {
		return new EligibilityRule(
			allowed_statuses: self::allowed_statuses(),
			excluded_order_types: HardExclusions::order_types(),
			excluded_product_types: HardExclusions::product_types()
		);
	}
}
