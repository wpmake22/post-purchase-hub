<?php
/**
 * The reorder reconciliation screen on the customer's order page.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderLine;
use PostPurchaseHub\Actions\ReorderPlan;
use PostPurchaseHub\Security\Sanitizer;

/**
 * Draws the summary the customer must read before any cart is touched, and
 * takes WooCommerce's own unreconciled button off the same page.
 *
 * Both halves belong together because they are one decision: while this plugin
 * offers a reconciled reorder for an order, core's `Order again` button — which
 * empties the cart and silently drops whatever no longer resolves — is a
 * one-click way around the screen this milestone exists to show. It is removed
 * for an eligible order (superseded) and for an order excluded by
 * `Integrations\Compat\HardExclusions` (deliberately not offered at all, per
 * docs/SPEC.md risk T5). Every other case is left exactly as core had it.
 *
 * The summary itself is a read: it renders for the order whose page is already
 * being viewed, from a query argument that must match that order's own id, so
 * it confers no access and reveals nothing a visitor could not already see.
 * The confirmation is a separate POST to `Rest\ReorderController`.
 *
 * @since 0.12.0
 */
final class ReorderView {

	/**
	 * Priority at which core's own reorder button is removed.
	 *
	 * Before 10, where WooCommerce renders the order details table that fires
	 * the hook core's button hangs off.
	 *
	 * @var int
	 */
	private const SUPPRESS_PRIORITY = 5;

	/**
	 * Priority at which the summary renders.
	 *
	 * Between the timeline (Renderer, 20) and the actions list
	 * (ActionsRenderer, 25): the summary is the answer to a button in that
	 * list, so it reads as belonging to it rather than to the order table.
	 *
	 * @var int
	 */
	private const SUMMARY_PRIORITY = 22;

	/**
	 * Core's reorder button callback.
	 *
	 * @var string
	 */
	private const CORE_BUTTON_CALLBACK = 'woocommerce_order_again_button';

	/**
	 * Core's hook that callback hangs off.
	 *
	 * @var string
	 */
	private const CORE_BUTTON_HOOK = 'woocommerce_order_details_after_order_table';

	/**
	 * Order ids whose summary has already been drawn this request.
	 *
	 * Mirrors Renderer::$rendered: the replacement template re-fires
	 * `woocommerce_view_order`, and drawing two confirmation forms for one
	 * order would give the page two submit buttons for one cart.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @since 0.12.0
	 *
	 * @param Reorder        $reorder   The action this screen belongs to.
	 * @param TemplateLoader $templates Template loader.
	 */
	public function __construct( private Reorder $reorder, private TemplateLoader $templates ) {}

	/**
	 * Wires both hooks.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_view_order', array( $this, 'supersede_core_button' ), self::SUPPRESS_PRIORITY );
		add_action( 'woocommerce_view_order', array( $this, 'render' ), self::SUMMARY_PRIORITY );
	}

	/**
	 * Removes core's unreconciled reorder button for orders this plugin speaks for.
	 *
	 * @since 0.12.0
	 *
	 * @param mixed $order_id Order id, as passed by woocommerce_view_order.
	 * @return void
	 */
	public function supersede_core_button( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$result   = $this->reorder->check( $order );
		$excluded = in_array(
			(string) $result->reason_code,
			array( 'order_type_excluded', 'product_type_excluded' ),
			true
		);

		if ( ! $result->eligible && ! $excluded ) {
			return;
		}

		/**
		 * Filters whether this plugin's reorder replaces WooCommerce's own button.
		 *
		 * True — the default — means one reorder path on the page, the one that
		 * shows the customer what changed. False leaves core's button in place
		 * alongside it, which also leaves the way around the reconciliation
		 * screen open.
		 *
		 * @since 0.12.0
		 *
		 * @param bool      $supersede Whether to remove core's button.
		 * @param \WC_Order $order     Order being viewed.
		 */
		if ( ! (bool) apply_filters( 'pph_reorder_supersedes_core_button', true, $order ) ) {
			return;
		}

		remove_action( self::CORE_BUTTON_HOOK, self::CORE_BUTTON_CALLBACK );
	}

	/**
	 * Renders the reconciliation summary, when this request asked for it.
	 *
	 * @since 0.12.0
	 *
	 * @param mixed $order_id Order id, as passed by woocommerce_view_order.
	 * @return void
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || $order->get_id() !== $this->requested_order_id() ) {
			return;
		}

		if ( isset( $this->rendered[ $order->get_id() ] ) ) {
			return;
		}

		$this->rendered[ $order->get_id() ] = true;

		if ( ! $this->reorder->check( $order )->eligible ) {
			return;
		}

		// The summary states what is in this order and what it costs now.
		Sanitizer::nocache();

		$this->templates->render(
			'partials/reorder-summary.php',
			array( 'reorder' => $this->view_model( $order, $this->reorder->preview( $order ) ) )
		);
	}

	/**
	 * The order id this request asked for a summary of.
	 *
	 * @since 0.12.0
	 * @return int Zero when the request asked for none.
	 */
	private function requested_order_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the value only ever matches the order whose page is already being rendered, and nothing is mutated on this path.
		return isset( $_GET[ Reorder::QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ Reorder::QUERY_ARG ] ) ) : 0;
	}

	/**
	 * Builds everything the template prints, already formatted.
	 *
	 * @since 0.12.0
	 *
	 * @param \WC_Order   $order Order being reordered.
	 * @param ReorderPlan $plan  Plan to present.
	 * @return array<string, mixed>
	 */
	private function view_model( \WC_Order $order, ReorderPlan $plan ): array {
		$lines = array();

		foreach ( $plan->lines as $line ) {
			$lines[] = $this->line_model( $line, $order );
		}

		return array(
			'order_id'      => $order->get_id(),
			'lines'         => $lines,
			'cart_items'    => $this->reorder->cart_item_count(),
			'can_confirm'   => $plan->has_addable(),
			'default_mode'  => Reorder::default_mode(),
			'merge_label'   => __( 'Add these to my current cart', 'post-purchase-hub' ),
			'replace_label' => __( 'Replace what is in my cart', 'post-purchase-hub' ),
			'confirm_label' => __( 'Add to cart', 'post-purchase-hub' ),
			'unavailable'   => $plan->nothing_available()
				? __( 'None of the items on this order can be bought again right now. Your cart has not been changed.', 'post-purchase-hub' )
				: '',
			'capped_notice' => $plan->was_capped()
				? sprintf(
					/* translators: %d: number of items checked. */
					__( 'This order has more items than we can check at once. The first %d were checked; the rest are listed below but will not be added — you can buy those from their product pages.', 'post-purchase-hub' ),
					$plan->item_cap
				)
				: '',
		);
	}

	/**
	 * One line, as the template prints it.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line  Line to present.
	 * @param \WC_Order   $order Order the line belongs to, for its currency.
	 * @return array<string, mixed>
	 */
	private function line_model( ReorderLine $line, \WC_Order $order ): array {
		return array(
			'outcome'    => $line->outcome,
			'name'       => $line->name,
			'quantity'   => $line->quantity,
			'requested'  => $line->requested_quantity,
			'status'     => $this->status_label( $line ),
			'price_note' => $this->price_note( $line, $order ),
			'url'        => ReorderLine::OUTCOME_VARIATION_CHANGED === $line->outcome ? $line->url : '',
		);
	}

	/**
	 * The sentence stating this line's outcome.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line Line to describe.
	 * @return string
	 */
	private function status_label( ReorderLine $line ): string {
		switch ( $line->outcome ) {
			case ReorderLine::OUTCOME_ADDED:
				return __( 'Will be added', 'post-purchase-hub' );
			case ReorderLine::OUTCOME_QUANTITY_REDUCED:
				return sprintf(
					/* translators: 1: quantity originally bought, 2: quantity available now. */
					__( 'Only %2$d of %1$d still available — adding %2$d', 'post-purchase-hub' ),
					$line->requested_quantity,
					$line->quantity
				);
			case ReorderLine::OUTCOME_OUT_OF_STOCK:
				return __( 'Out of stock — not added', 'post-purchase-hub' );
			case ReorderLine::OUTCOME_VARIATION_CHANGED:
				return __( 'This option is no longer available — not added', 'post-purchase-hub' );
			case ReorderLine::OUTCOME_NOT_CHECKED:
				return __( 'Not checked — not added', 'post-purchase-hub' );
			default:
				return __( 'No longer sold — not added', 'post-purchase-hub' );
		}
	}

	/**
	 * The price movement on a line that will be added, if any.
	 *
	 * @since 0.12.0
	 *
	 * @param ReorderLine $line  Line to describe.
	 * @param \WC_Order   $order Order the line belongs to, for its currency.
	 * @return string
	 */
	private function price_note( ReorderLine $line, \WC_Order $order ): string {
		if ( ! $line->is_addable() || ! $line->price_changed() ) {
			return '';
		}

		$delta = (float) $line->price_delta();

		return sprintf(
			/* translators: 1: price paid before, 2: price now, 3: signed difference. */
			__( 'Price changed: was %1$s, now %2$s (%3$s each)', 'post-purchase-hub' ),
			$this->money( (float) $line->original_price, $order ),
			$this->money( (float) $line->current_price, $order ),
			( $delta > 0 ? '+' : '-' ) . $this->money( abs( $delta ), $order )
		);
	}

	/**
	 * A formatted amount, as plain text.
	 *
	 * `wc_price()` returns markup and HTML entities; the template escapes what
	 * it prints, so the markup is stripped and the entities decoded here rather
	 * than printed back at the customer as `&#36;`.
	 *
	 * @since 0.12.0
	 *
	 * @param float     $value Amount.
	 * @param \WC_Order $order Order supplying the currency.
	 * @return string
	 */
	private function money( float $value, \WC_Order $order ): string {
		$formatted = wc_price( $value, array( 'currency' => $order->get_currency() ) );

		return html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' );
	}
}
