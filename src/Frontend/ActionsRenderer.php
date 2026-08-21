<?php
/**
 * Renders eligible actions on the customer's order pages.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Actions\ActionRegistry;

/**
 * The only place a registered action's resolver is ever called for rendering.
 *
 * List context reuses WooCommerce's own `woocommerce_my_account_my_orders_actions`
 * filter rather than duplicating it, per this milestone's brief — and because
 * that array already carries core's own `pay`/`view`/`cancel` entries, a
 * registered action that shares a key with one of them (`cancel`, deliberately,
 * once Cancel registers in a later milestone) replaces it outright, both when
 * eligible (a different entry under the same key) and when not (unset removes
 * core's own entry too). Detail context has no equivalent core mechanism —
 * `templates/myaccount/view-order.php` fires only `woocommerce_view_order` —
 * so eligible actions there are drawn through this plugin's own partial.
 *
 * Eligibility itself is never decided here: each action's resolver is what
 * calls `EligibilityResolver`, so this class stays ignorant of any action's
 * specific rule and cannot drift out of step with it.
 *
 * @since 0.7.0
 */
final class ActionsRenderer {

	/**
	 * Priority of the detail-page hook.
	 *
	 * After Renderer's own detail hook (20), so actions render below the
	 * timeline rather than above it.
	 *
	 * @var int
	 */
	private const DETAIL_PRIORITY = 25;

	/**
	 * Registry of actions to draw from.
	 *
	 * @var ActionRegistry
	 */
	private ActionRegistry $registry;

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Order ids whose detail actions have already been drawn this request.
	 *
	 * Mirrors Renderer::$rendered: replacement mode's own template also fires
	 * `woocommerce_view_order`, so without this an order in that mode would
	 * draw its actions twice.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param ActionRegistry $registry  Registry of actions to draw from.
	 * @param TemplateLoader $templates Template loader.
	 */
	public function __construct( ActionRegistry $registry, TemplateLoader $templates ) {
		$this->registry  = $registry;
		$this->templates = $templates;
	}

	/**
	 * Wires the rendering hooks.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'filter_list_actions' ), 10, 2 );

		// Only woocommerce_view_order is hooked, at a priority after Renderer's
		// own (20): the replacement template re-fires this same hook (see
		// templates/myaccount/view-order.php), so additive and replacement
		// modes both draw actions through this one callback, below the
		// timeline, with no separate hand-off action needed.
		add_action( 'woocommerce_view_order', array( $this, 'render_detail' ), self::DETAIL_PRIORITY );
	}

	/**
	 * Adds and removes this plugin's actions from the My Account list array.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $actions Actions array as WooCommerce built it.
	 * @param mixed $order   Order the row belongs to.
	 * @return array<string, mixed>
	 */
	public function filter_list_actions( $actions, $order ): array {
		if ( ! is_array( $actions ) || ! $order instanceof \WC_Order ) {
			return is_array( $actions ) ? $actions : array();
		}

		foreach ( $this->registry->for_context( 'list' ) as $action ) {
			$payload = $action->resolve( $order, 'list' );

			if ( null === $payload ) {
				unset( $actions[ $action->id ] );
				continue;
			}

			$actions[ $action->id ] = $payload;
		}

		return $actions;
	}

	/**
	 * Renders the detail-page actions from the woocommerce_view_order hook, at
	 * most once per order per request.
	 *
	 * The guard exists for the same reason Renderer's does: something else
	 * hooked to this action calling it again mid-request must not draw the
	 * actions list twice.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $order_id Order id, as passed by woocommerce_view_order.
	 * @return void
	 */
	public function render_detail( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$id = $order->get_id();

		if ( isset( $this->rendered[ $id ] ) ) {
			return;
		}

		$this->rendered[ $id ] = true;

		$rows = array();

		foreach ( $this->registry->for_context( 'detail' ) as $action ) {
			$payload = $action->resolve( $order, 'detail' );

			if ( null === $payload ) {
				continue;
			}

			$rows[] = array(
				'id'          => $action->id,
				'label'       => (string) ( $payload['name'] ?? $action->label ),
				'url'         => (string) ( $payload['url'] ?? '' ),
				'description' => (string) ( $payload['description'] ?? '' ),
			);
		}

		$this->templates->render( 'partials/actions.php', array( 'actions' => $rows ) );
	}
}
