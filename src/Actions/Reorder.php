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
 * from core's own filter via `ReorderOptions`, and the same cart API
 * underneath (`WooCommerceCart`) — and changes only the three things that make
 * it trustworthy:
 *
 * 1. Nothing is mutated until the customer has seen the reconciliation summary
 *    and confirmed it, over POST (CLAUDE.md hard rule 4).
 * 2. A line that cannot be bought again says which reason applies
 *    (`ReorderPlanner`), rather than vanishing.
 * 3. A non-empty cart is merged into by default rather than emptied, and the
 *    customer is offered the choice either way.
 *
 * Eligibility here has two independent dimensions, deliberately named and
 * asked separately, because they fail for unrelated reasons: `order_eligibility()`
 * is about the order (status, type, product types) and `visitor_eligibility()`
 * is about the request (v1 carts need an account). `check()` composes them and
 * is the single gate every caller uses — the render path, the summary screen
 * and the REST route alike, so none of them can enforce a different rule.
 *
 * Ownership is not one of those dimensions: `Security\OwnershipResolver`
 * decides it, once, before any caller reaches this class.
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
	 * Denial code for a request that cannot hold a cart.
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
	 * Both dimensions, cheapest first. The single gate: nothing decides
	 * reorder eligibility anywhere else, in any layer.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function check( \WC_Order $order ): EligibilityResult {
		$visitor = self::visitor_eligibility();

		if ( ! $visitor->eligible ) {
			return $visitor;
		}

		return $this->order_eligibility( $order );
	}

	/**
	 * Whether the order itself qualifies, ignoring who is asking.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function order_eligibility( \WC_Order $order ): EligibilityResult {
		return $this->eligibility->resolve( self::ID, $order, $this->rule() );
	}

	/**
	 * Whether this request can hold a cart at all, ignoring which order it is
	 * about.
	 *
	 * A cart belongs to a session, and v1 does not open a cart-bearing session
	 * for a token-bearing guest — the same condition core's own `order_again`
	 * imposes. It lives here, in the action, rather than in the template or
	 * the controller, because a guest holding a valid signed link reaches the
	 * REST route too: a rule enforced only where a button is drawn is not
	 * enforced.
	 *
	 * @since 0.12.0
	 *
	 * @return EligibilityResult
	 */
	public static function visitor_eligibility(): EligibilityResult {
		if ( ! is_user_logged_in() ) {
			return EligibilityResult::denied(
				self::REASON_LOGIN_REQUIRED,
				__( 'Sign in to your account to buy these items again.', 'post-purchase-hub' )
			);
		}

		return EligibilityResult::allowed();
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
		return $this->planner->plan( $order, ReorderOptions::item_cap() );
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
	 * @param string    $mode  One of ReorderOptions::modes().
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

		$mode = ReorderOptions::normalise_mode( $mode );

		if ( ReorderOptions::MODE_REPLACE === $mode ) {
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
			allowed_statuses: ReorderOptions::allowed_statuses(),
			excluded_order_types: HardExclusions::order_types(),
			excluded_product_types: HardExclusions::product_types()
		);
	}
}
