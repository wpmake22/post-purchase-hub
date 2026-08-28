<?php
/**
 * Linked-requests metabox on the order edit screen.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Requests\RequestRepository;

/**
 * Shows every request raised against the order being edited, with a link
 * into the queue — nothing here mutates anything, so no capability check is
 * needed beyond the one WordPress already enforces to reach this screen.
 *
 * @since 0.9.0
 */
final class OrderMetabox {

	/**
	 * Metabox id.
	 *
	 * @var string
	 */
	private const ID = 'pph-requests';

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestRepository $requests Reads the order's linked requests.
	 */
	public function __construct( private RequestRepository $requests ) {}

	/**
	 * Wires the metabox hook, on both the legacy post-based screen and HPOS's
	 * own order screen.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
	}

	/**
	 * Adds the metabox to every screen that can show a single order.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function add_metabox(): void {
		foreach ( self::order_screen_ids() as $screen_id ) {
			add_meta_box(
				self::ID,
				__( 'Post-Purchase Hub', 'wpmake-post-purchase-hub' ),
				array( $this, 'render' ),
				$screen_id,
				'side'
			);
		}
	}

	/**
	 * The screen ids that show a single order, legacy and HPOS.
	 *
	 * @since 0.9.0
	 * @return string[]
	 */
	private static function order_screen_ids(): array {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return array( \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen() );
		}

		return array( 'shop_order' );
	}

	/**
	 * Renders the metabox for the order currently being edited.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Whatever the screen passes: a post on the legacy screen, an order on HPOS's.
	 * @return void
	 */
	public function render( $post_or_order ): void {
		$order_id = $post_or_order instanceof \WC_Order ? $post_or_order->get_id() : (int) $post_or_order->ID;

		$requests = $order_id > 0 ? $this->requests->find_by_order( $order_id ) : array();

		if ( ! $requests ) {
			echo '<p>' . esc_html__( 'No requests for this order.', 'wpmake-post-purchase-hub' ) . '</p>';

			return;
		}

		echo '<ul class="pph-order-requests">';

		foreach ( $requests as $request ) {
			printf(
				'<li><a href="%s">%s (%s)</a></li>',
				esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE . '&request_id=' . $request->id ) ),
				esc_html( $request->type ),
				esc_html( $request->status )
			);
		}

		echo '</ul>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE . '&order_id=' . $order_id ) ),
			esc_html__( 'View in the request queue', 'wpmake-post-purchase-hub' )
		);
	}
}
