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
 * Registers one page: the request queue, which routes itself between the list
 * table and one request's detail view depending on whether `request_id` is
 * present in the query string. The settings screen registers its own entry
 * (`Admin\SettingsPage`), and the setup wizard deliberately registers none
 * (`Admin\Wizard`) — a permanent "Setup" item would still be offering to
 * configure a store that was configured years earlier.
 *
 * `RequestDetail` and `RequestListTable` are built here, on demand, inside
 * render() — never injected and held. `WP_List_Table::__construct()` calls
 * core's `convert_to_screen()`, which lives in `wp-admin/includes/template.php`
 * and is not loaded yet at the point `Plugin::register_rendering()` builds
 * this service (`init`, priority 20): building one any earlier than the
 * page callback itself firing is a fatal on every admin request.
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
	public const REQUESTS_PAGE = 'wpmphub-requests';

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestRepository $requests Counts pending requests for the menu bubble and backs both views, built fresh on each request rather than held.
	 */
	public function __construct( private RequestRepository $requests ) {}

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
			__( 'Post-Purchase Hub', 'wpmake-post-purchase-hub' ),
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
		return __( 'Requests', 'wpmake-post-purchase-hub' );
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
			( new RequestDetail( $this->requests ) )->render( $request_id );

			return;
		}

		$list_table = new RequestListTable( $this->requests );
		$list_table->prepare_items();
		$list_table->render_page();
	}
}
