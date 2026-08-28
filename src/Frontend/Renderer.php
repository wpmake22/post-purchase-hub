<?php
/**
 * Additive rendering on WooCommerce's own order surfaces.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\TimelineBuilder;

/**
 * Puts the timeline on the customer's order pages without replacing anything.
 *
 * Additive is the default and has to keep working alone, per hard rule 14. Every
 * theme and page builder styles `myaccount/orders.php` and `view-order.php`
 * differently, and that long tail is untestable — so this class adds a column
 * and a section through WooCommerce's own extension points and touches nothing
 * core already renders.
 *
 * Two decisions come straight from reading core, and both are deviations from
 * the milestone's literal wording:
 *
 * `woocommerce_my_account_my_orders_column_{$id}` does not add to a cell, it
 * replaces it — `myaccount/orders.php` runs the action *instead of* the default
 * output. Hooking an existing column would blank the store's status text, so a
 * column of our own is registered and only that column is filled.
 *
 * Only `woocommerce_view_order` is hooked on the detail page, not
 * `woocommerce_order_details_after_order_table` as well: core hooks its own
 * details table to `woocommerce_view_order`, so that second action fires from
 * inside the first and taking both would render the timeline twice.
 *
 * @since 0.4.0
 */
final class Renderer {

	/**
	 * Id of the column added to the orders table.
	 *
	 * @var string
	 */
	public const LIST_COLUMN = 'pph-timeline';

	/**
	 * Priority of the detail-page hook.
	 *
	 * Above core's own 10, so the timeline lands after the order details table
	 * rather than between the heading and the items.
	 *
	 * @var int
	 */
	private const DETAIL_PRIORITY = 20;

	/**
	 * Timeline builder.
	 *
	 * @var TimelineBuilder
	 */
	private TimelineBuilder $builder;

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Estimated-delivery calculator.
	 *
	 * @var EstimatedDelivery
	 */
	private EstimatedDelivery $eta;

	/**
	 * Pending-cancellation branch overlay.
	 *
	 * @var PendingCancellationBranch
	 */
	private PendingCancellationBranch $pending_cancellation;

	/**
	 * Order ids whose detail timeline has already been drawn this request.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param TimelineBuilder           $builder              Timeline builder.
	 * @param TemplateLoader            $templates            Template loader.
	 * @param EstimatedDelivery         $eta                  Estimated-delivery calculator.
	 * @param PendingCancellationBranch $pending_cancellation Pending-cancellation branch overlay, detail page only.
	 */
	public function __construct( TimelineBuilder $builder, TemplateLoader $templates, EstimatedDelivery $eta, PendingCancellationBranch $pending_cancellation ) {
		$this->builder              = $builder;
		$this->templates            = $templates;
		$this->eta                  = $eta;
		$this->pending_cancellation = $pending_cancellation;
	}

	/**
	 * Wires the rendering hooks.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_view_order', array( $this, 'render_detail' ), self::DETAIL_PRIORITY );

		if ( $this->shows_list_column() ) {
			add_filter( 'woocommerce_account_orders_columns', array( $this, 'add_list_column' ) );
			add_action( 'woocommerce_my_account_my_orders_column_' . self::LIST_COLUMN, array( $this, 'render_list_column' ) );
		}

		add_action( 'pph_render_timeline_partial', array( $this, 'render_prepared_timeline' ) );
		add_action( 'pph_render_order_notes', array( $this, 'render_order_notes' ) );
		add_action( 'pph_render_orders_list', array( $this, 'render_orders_list' ), 10, 2 );
		add_action( 'pph_render_order_detail', array( $this, 'render_replacement_detail' ) );
	}

	/**
	 * Adds this plugin's column to the orders table.
	 *
	 * Placed immediately after the status column, which is the one it explains.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $columns Column labels keyed by column id.
	 * @return array<string, string>
	 */
	public function add_list_column( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array();
		}

		$label    = _x( 'Progress', 'orders list column', 'wpmake-post-purchase-hub' );
		$position = array_search( 'order-status', array_keys( $columns ), true );

		if ( false === $position ) {
			$columns[ self::LIST_COLUMN ] = $label;

			return $columns;
		}

		return array_merge(
			array_slice( $columns, 0, (int) $position + 1, true ),
			array( self::LIST_COLUMN => $label ),
			array_slice( $columns, (int) $position + 1, null, true )
		);
	}

	/**
	 * Fills this plugin's column for one row.
	 *
	 * Uses the order object WooCommerce hands over and reads nothing else. The
	 * timeline lives in meta the CRUD object already loaded, so a twenty-row list
	 * costs no queries beyond the ones core was making anyway — calling
	 * wc_get_order() here instead would cost one per row on post storage.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $order Order for this row.
	 * @return void
	 */
	public function render_list_column( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->templates->render(
			'partials/timeline-summary.php',
			array( 'timeline' => TimelineView::present( $this->builder->build( $order ) ) )
		);
	}

	/**
	 * Renders the timeline on the order detail page.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $order_id Order id, as passed by woocommerce_view_order.
	 * @return void
	 */
	public function render_detail( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->render_detail_once( $order );
		}
	}

	/**
	 * Renders an order's detail timeline at most once per request.
	 *
	 * In replacement mode this plugin's own template fires
	 * `woocommerce_view_order` so that integrations hooked there keep working —
	 * which means the hook that draws the timeline additively fires too. Rather
	 * than unhooking conditionally and hoping the two stay in step, the draw
	 * itself is made idempotent.
	 *
	 * @since 0.4.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return void
	 */
	private function render_detail_once( \WC_Order $order ): void {
		$id = $order->get_id();

		if ( isset( $this->rendered[ $id ] ) ) {
			return;
		}

		$this->rendered[ $id ] = true;

		$this->render_timeline( $order );
	}

	/**
	 * Renders the timeline for an order object.
	 *
	 * @since 0.4.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return void
	 */
	public function render_timeline( \WC_Order $order ): void {
		$this->templates->render(
			'partials/timeline.php',
			array(
				'timeline' => TimelineView::present(
					$this->builder->build( $order ),
					$this->pending_cancellation->for_order( $order )
				),
			)
		);

		$this->templates->render(
			'partials/eta.php',
			array( 'eta' => EstimatedDeliveryView::present( $this->eta->for_order( $order ) ) )
		);
	}

	/**
	 * Renders an already-prepared timeline.
	 *
	 * The hand-off a template uses to draw a nested partial without holding the
	 * loader, which is what keeps templates free of anything but echoing.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $timeline Prepared timeline view model.
	 * @return void
	 */
	public function render_prepared_timeline( $timeline ): void {
		if ( is_array( $timeline ) ) {
			$this->templates->render( 'partials/timeline.php', array( 'timeline' => $timeline ) );
		}
	}

	/**
	 * Renders a list of orders with their timelines.
	 *
	 * Serves the shortcode, the block and replacement mode, all of which need the
	 * same thing: orders the viewer already owns, each with its progress.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $orders    Orders to render.
	 * @param mixed $empty_text Message shown when there are none.
	 * @return void
	 */
	public function render_orders_list( $orders, $empty_text = '' ): void {
		$rows = array();

		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$rows[] = array(
				'number'   => $order->get_order_number(),
				'url'      => $order->get_view_order_url(),
				'timeline' => TimelineView::present( $this->builder->build( $order ) ),
			);
		}

		$this->templates->render(
			'partials/orders-list.php',
			array(
				'orders'     => $rows,
				'empty_text' => is_string( $empty_text ) && '' !== $empty_text
					? $empty_text
					: __( 'No orders yet.', 'wpmake-post-purchase-hub' ),
			)
		);
	}

	/**
	 * Renders the merchant's notes to the customer.
	 *
	 * WooCommerce's own view-order template lists these as "Order updates", and
	 * replacement mode does not render that template. Losing a merchant's words
	 * to a customer because a rendering setting changed is a data loss the
	 * customer notices and the merchant does not, so replacement carries them.
	 *
	 * @since 0.4.1
	 *
	 * @param mixed $order Order whose notes to show.
	 * @return void
	 */
	public function render_order_notes( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$notes = OrderNotesView::present( $order );

		if ( array() === $notes ) {
			return;
		}

		$this->templates->render( 'partials/order-notes.php', array( 'notes' => $notes ) );
	}

	/**
	 * Renders the detail timeline from the replacement template's hand-off.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $order Order supplied by the replacement template.
	 * @return void
	 */
	public function render_replacement_detail( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->render_detail_once( $order );
		}
	}

	/**
	 * Whether the orders table gets an extra column.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private function shows_list_column(): bool {
		/**
		 * Filters whether a progress column is added to the My Account orders table.
		 *
		 * A merchant whose theme lays that table out tightly, or who has already
		 * added columns of their own, may want the timeline on the detail page
		 * only. Returning false leaves core's table exactly as it was.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $enabled Whether to add the column.
		 */
		return (bool) apply_filters( 'pph_orders_list_column', true );
	}
}
