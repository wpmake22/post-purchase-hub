<?php
/**
 * The admin request queue's menu and page router.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;

/**
 * One submenu under WooCommerce's own top-level menu — no top-level menu of
 * this plugin's own, per the milestone brief.
 *
 * Registers a single page for now: the request queue, which routes itself
 * between the list table and one request's detail view depending on whether
 * `request_id` is present in the query string. `Admin\SettingsPage` (M14)
 * adds its own submenu item later; registering one now, before that page
 * exists, would be exactly the dead button hard rule 19 forbids.
 *
 * @since 0.9.0
 */
final class Menu {

	/**
	 * Capability required to see or act on this menu.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'edit_shop_orders';

	/**
	 * Slug of the request queue page.
	 *
	 * @var string
	 */
	public const REQUESTS_PAGE = 'pph-requests';

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestRepository $requests    Counts pending requests for the menu bubble and serves the list table.
	 * @param RequestDetail     $detail      Renders one request's detail view.
	 * @param RequestListTable  $list_table  Renders the paginated queue.
	 */
	public function __construct(
		private RequestRepository $requests,
		private RequestDetail $detail,
		private RequestListTable $list_table
	) {}

	/**
	 * Wires the admin_menu hook.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the submenu page.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Post-Purchase Hub', 'post-purchase-hub' ),
			self::menu_title() . self::bubble( $this->pending_count() ),
			self::CAPABILITY,
			self::REQUESTS_PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * The menu title, with a pending-count bubble when there is one to show.
	 *
	 * @since 0.9.0
	 * @return string
	 */
	public static function menu_title(): string {
		return __( 'Requests', 'post-purchase-hub' );
	}

	/**
	 * How many pending requests should show as the menu bubble.
	 *
	 * @since 0.9.0
	 * @return int
	 */
	public function pending_count(): int {
		return $this->requests->count( array( 'status' => Request::STATUS_PENDING ) );
	}

	/**
	 * The bubble markup appended to the menu title, matching the markup core
	 * uses for its own "Comments"/"Updates" counters.
	 *
	 * @since 0.9.0
	 *
	 * @param int $count Pending count.
	 * @return string
	 */
	public static function bubble( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);
	}

	/**
	 * Renders the page: one request's detail view, or the queue.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation, not a state change.
		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;

		if ( $request_id > 0 ) {
			$this->detail->render( $request_id );

			return;
		}

		$this->list_table->prepare_items();
		$this->list_table->render_page();
	}
}
