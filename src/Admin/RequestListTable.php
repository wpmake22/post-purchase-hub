<?php
/**
 * Paginated queue of customer requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestQuery;
use PostPurchaseHub\Requests\RequestRepository;

if ( ! class_exists( '\WP_List_Table' ) && defined( 'ABSPATH' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A real `WP_List_Table` over `RequestRepository`: whatever the total row
 * count, rendering one page always costs exactly one `query()` and one
 * `count()` call — never a query per row, and never `SELECT *`.
 *
 * Columns show what a merchant needs to triage without opening the order:
 * the order (deep-linked), who asked, what and why, how long it has waited,
 * its status, and — for a still-open request — the approve/decline forms.
 * Loading the order per visible row is bounded by page size, not by how many
 * requests exist in total, which is what the milestone's "constant query
 * count" acceptance criterion is actually asking for.
 *
 * @since 0.9.0
 */
final class RequestListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Columns a merchant may filter or sort by, and the request field each maps to.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'order_id', 'type', 'status', 'created_at' );

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestRepository $requests Backing store.
	 */
	public function __construct( private RequestRepository $requests ) {
		parent::__construct(
			array(
				'singular' => 'pph_request',
				'plural'   => 'pph_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column headings.
	 *
	 * @since 0.9.0
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'request'  => __( 'Request', 'post-purchase-hub' ),
			'order'    => __( 'Order', 'post-purchase-hub' ),
			'customer' => __( 'Customer', 'post-purchase-hub' ),
			'type'     => __( 'Type', 'post-purchase-hub' ),
			'reason'   => __( 'Reason', 'post-purchase-hub' ),
			'age'      => __( 'Age', 'post-purchase-hub' ),
			'status'   => __( 'Status', 'post-purchase-hub' ),
			'actions'  => __( 'Actions', 'post-purchase-hub' ),
		);
	}

	/**
	 * Sortable columns, restricted to RequestQuery's own whitelist.
	 *
	 * @since 0.9.0
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'request' => array( 'id', false ),
			'order'   => array( 'order_id', false ),
			'type'    => array( 'type', false ),
			'status'  => array( 'status', false ),
			'age'     => array( 'created_at', true ),
		);
	}

	/**
	 * Builds the page's rows and pagination, from exactly one query() and one
	 * count() call regardless of how many requests exist in total.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$filters  = self::filters_from_request();
		$orderby  = self::orderby_from_request();
		$order    = self::order_from_request();
		$page     = $this->get_pagenum();
		$per_page = self::PER_PAGE;

		$total = $this->requests->count( $filters );

		$this->items = $this->requests->query( $filters, $orderby, $order, $page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( max( 1, $total ) / $per_page ),
			)
		);
	}

	/**
	 * Filters read from the query string, validated against what
	 * `RequestQuery::where()` accepts — an unrecognised or malformed value is
	 * dropped rather than passed through, so a bad filter link empties the
	 * table instead of throwing.
	 *
	 * @since 0.9.0
	 * @return array<string, mixed>
	 */
	private static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filtering a list view is a read, not a state change.
		$filters = array();

		$type = isset( $_GET['type'] ) && is_scalar( $_GET['type'] ) ? sanitize_key( (string) $_GET['type'] ) : '';

		if ( in_array( $type, Request::types(), true ) ) {
			$filters['type'] = $type;
		}

		$status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';

		if ( in_array( $status, Request::statuses(), true ) ) {
			$filters['status'] = $status;
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( $order_id > 0 ) {
			$filters['order_id'] = $order_id;
		}

		$after = self::day_boundary( self::get_string( 'created_after' ), false );

		if ( null !== $after ) {
			$filters['created_after'] = $after;
		}

		$before = self::day_boundary( self::get_string( 'created_before' ), true );

		if ( null !== $before ) {
			$filters['created_before'] = $before;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $filters;
	}

	/**
	 * Turns a `Y-m-d` date filter input into the start or end of that day, in
	 * `RequestQuery::DATE_FORMAT`.
	 *
	 * @since 0.9.0
	 *
	 * @param string $date   Candidate `Y-m-d` date.
	 * @param bool   $end_of Whether to anchor to the end of the day rather than the start.
	 * @return string|null
	 */
	private static function day_boundary( string $date, bool $end_of ): ?string {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		return $parsed->format( 'Y-m-d' ) . ( $end_of ? ' 23:59:59' : ' 00:00:00' );
	}

	/**
	 * The sort column from the query string, whitelisted.
	 *
	 * @since 0.9.0
	 * @return string
	 */
	private static function orderby_from_request(): string {
		$orderby = self::get_string( 'orderby', 'created_at' );

		return in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'created_at';
	}

	/**
	 * The sort direction from the query string, whitelisted.
	 *
	 * @since 0.9.0
	 * @return string
	 */
	private static function order_from_request(): string {
		$order = strtoupper( self::get_string( 'order', 'DESC' ) );

		return in_array( $order, RequestQuery::ORDER, true ) ? $order : 'DESC';
	}

	/**
	 * Reads a scalar `$_GET` value, sanitised. Filtering and sorting a list
	 * view is a read, not a state change, so no nonce is required here.
	 *
	 * @since 0.9.0
	 *
	 * @param string $key          Query var name.
	 * @param string $fallback Value when absent or not scalar.
	 * @return string
	 */
	private static function get_string( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtering/sorting a list view is a read, not a state change.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return $fallback;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed and sanitised on the line below.
		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}

	/**
	 * Renders the page chrome around the table: heading and filters, then the
	 * table itself.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Post-Purchase Hub', 'post-purchase-hub' ) . '</h1>';
		$this->render_filters();
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Menu::REQUESTS_PAGE ) );
		$this->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the type/status filter dropdowns.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	private function render_filters(): void {
		$filters = self::filters_from_request();

		echo '<form method="get" class="pph-request-filters">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Menu::REQUESTS_PAGE ) );

		self::render_select( 'type', __( 'All types', 'post-purchase-hub' ), Request::types(), $filters['type'] ?? '' );
		self::render_select( 'status', __( 'All statuses', 'post-purchase-hub' ), Request::statuses(), $filters['status'] ?? '' );

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'post-purchase-hub' ) );
		echo '</form>';
	}

	/**
	 * Renders one filter dropdown.
	 *
	 * @since 0.9.0
	 *
	 * @param string   $name    Field name.
	 * @param string   $all     Label for "no filter".
	 * @param string[] $options Option values.
	 * @param string   $current Currently selected value.
	 * @return void
	 */
	private static function render_select( string $name, string $all, array $options, string $current ): void {
		printf( '<select name="%s">', esc_attr( $name ) );
		printf( '<option value="">%s</option>', esc_html( $all ) );

		foreach ( $options as $option ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option ),
				selected( $current, $option, false ),
				esc_html( $option )
			);
		}

		echo '</select>';
	}

	/**
	 * The empty state.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function no_items(): void {
		printf(
			'%s<br><a href="%s">%s</a>',
			esc_html__( 'No requests yet. When a customer asks to cancel an order, it appears here.', 'post-purchase-hub' ),
			esc_url( self::my_account_orders_url() ),
			esc_html__( 'Preview the customer-facing order page', 'post-purchase-hub' )
		);
	}

	/**
	 * The customer-facing My Account orders URL, for the empty state's preview link.
	 *
	 * @since 0.9.0
	 * @return string
	 */
	private static function my_account_orders_url(): string {
		if ( ! function_exists( 'wc_get_page_permalink' ) || ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		return wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * Default rendering for a column with no dedicated method.
	 *
	 * @since 0.9.0
	 *
	 * @param object|array<string, mixed> $item        Row, a Request instance.
	 * @param string                      $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		if ( ! $item instanceof Request ) {
			return '';
		}

		switch ( $column_name ) {
			case 'type':
				return esc_html( $item->type );
			case 'reason':
				return esc_html( (string) $item->reason_code );
			case 'status':
				return esc_html( $item->status );
			default:
				return '';
		}
	}

	/**
	 * The "request" column: id, linked to the detail view.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item Row.
	 * @return string
	 */
	public function column_request( Request $item ): string {
		return sprintf(
			'<a href="%s">#%d</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE . '&request_id=' . $item->id ) ),
			$item->id
		);
	}

	/**
	 * The "order" column: order number, deep-linked to the order edit screen.
	 *
	 * One order lookup per visible row — bounded by page size, not by how
	 * many requests exist in the table, which is what the milestone's
	 * constant-query-count acceptance criterion is about.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item Row.
	 * @return string
	 */
	public function column_order( Request $item ): string {
		$order = wc_get_order( $item->order_id );

		if ( ! $order instanceof \WC_Order ) {
			/* translators: %d: order id. */
			return sprintf( esc_html__( '#%d (not found)', 'post-purchase-hub' ), $item->order_id );
		}

		return sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) );
	}

	/**
	 * The "customer" column: billing name when the order still resolves.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item Row.
	 * @return string
	 */
	public function column_customer( Request $item ): string {
		$order = wc_get_order( $item->order_id );

		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$name = trim( $order->get_formatted_billing_full_name() );

		return '' !== $name ? esc_html( $name ) : esc_html( $order->get_billing_email() );
	}

	/**
	 * The "age" column: time since the request was created.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item Row.
	 * @return string
	 */
	public function column_age( Request $item ): string {
		$created = strtotime( $item->created_at . ' UTC' );

		if ( false === $created ) {
			return '';
		}

		/* translators: %s: human-readable time, e.g. "3 hours". */
		return sprintf( esc_html__( '%s ago', 'post-purchase-hub' ), esc_html( human_time_diff( $created ) ) );
	}

	/**
	 * The "actions" column: approve/decline forms for an open request, a
	 * "view" link otherwise.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item Row.
	 * @return string
	 */
	public function column_actions( Request $item ): string {
		if ( ! $item->is_open() ) {
			return sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE . '&request_id=' . $item->id ) ),
				esc_html__( 'View', 'post-purchase-hub' )
			);
		}

		return $this->action_form( $item, RequestActionController::APPROVE_ACTION, __( 'Approve', 'post-purchase-hub' ) )
			. ' ' . $this->action_form( $item, RequestActionController::DECLINE_ACTION, __( 'Decline', 'post-purchase-hub' ) );
	}

	/**
	 * One row-action form.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $item   Row.
	 * @param string  $action admin-post.php action name.
	 * @param string  $label  Button label.
	 * @return string
	 */
	private function action_form( Request $item, string $action, string $label ): string {
		ob_start();

		echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="request_id" value="%d">', (int) $item->id );
		wp_nonce_field( RequestActionController::NONCE_ACTION );
		printf( '<button type="submit" class="button button-small">%s</button>', esc_html( $label ) );
		echo '</form>';

		return (string) ob_get_clean();
	}
}
