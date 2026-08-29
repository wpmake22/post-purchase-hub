<?php
/**
 * Removes everything the M16 manual verification run created on a local site.
 *
 * Run with:
 *   wp eval-file tests/e2e/utils/cleanup-local-fixtures.php
 *
 * The automated e2e suite cleans up after itself (see utils/orders.js); this is
 * for the by-hand pass a developer does against a Local/DDEV site, where the
 * fixtures deliberately outlive the session so the next person can look at them.
 *
 * @package PostPurchaseHub
 */

$wpmphub_removed = array(
	'orders'   => 0,
	'users'    => 0,
	'pages'    => 0,
	'requests' => 0,
);

foreach ( wc_get_orders(
	array(
		'limit'        => -1,
		'status'       => 'any',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-off teardown on a development site; there is no indexed alternative for "orders this run created".
		'meta_key'     => '_wpmphub_m16_fixture',
		'meta_compare' => 'EXISTS',
		'return'       => 'ids',
	)
) as $wpmphub_order_id ) {
	$wpmphub_order = wc_get_order( $wpmphub_order_id );

	if ( $wpmphub_order ) {
		$order->delete( true );
		++$wpmphub_removed['orders'];
	}
}

foreach ( array( 'wpmphub_customer', 'wpmphub_manager' ) as $wpmphub_login ) {
	$wpmphub_user = get_user_by( 'login', $wpmphub_login );

	if ( $wpmphub_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $wpmphub_user->ID );
		++$wpmphub_removed['users'];
	}
}

$wpmphub_page = get_page_by_path( 'wpmphub-order-lookup' );

if ( $wpmphub_page ) {
	wp_delete_post( $wpmphub_page->ID, true );
	++$wpmphub_removed['pages'];
}

global $wpdb;

$wpmphub_requests_table = $wpdb->prefix . 'wpmphub_requests';
$wpmphub_items_table    = $wpdb->prefix . 'wpmphub_request_items';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Truncating this plugin's own tables by their Schema-built names.
$wpmphub_removed['requests'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpmphub_requests_table}" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
$wpdb->query( "TRUNCATE TABLE {$wpmphub_items_table}" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
$wpdb->query( "TRUNCATE TABLE {$wpmphub_requests_table}" );

// Settings and setup state are deliberately left alone: a developer who ran
// the wizard by hand probably wants the store still configured afterwards.
echo 'Removed: ' . wp_json_encode( $wpmphub_removed ) . "\n";
echo "Left in place: wpmphub_settings and wpmphub_setup_state.\n";
