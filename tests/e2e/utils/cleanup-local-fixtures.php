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

$pph_removed = array(
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
		'meta_key'     => '_pph_m16_fixture',
		'meta_compare' => 'EXISTS',
		'return'       => 'ids',
	)
) as $pph_order_id ) {
	$pph_order = wc_get_order( $pph_order_id );

	if ( $pph_order ) {
		$order->delete( true );
		++$pph_removed['orders'];
	}
}

foreach ( array( 'pph_customer', 'pph_manager' ) as $pph_login ) {
	$pph_user = get_user_by( 'login', $pph_login );

	if ( $pph_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $pph_user->ID );
		++$pph_removed['users'];
	}
}

$pph_page = get_page_by_path( 'pph-order-lookup' );

if ( $pph_page ) {
	wp_delete_post( $pph_page->ID, true );
	++$pph_removed['pages'];
}

global $wpdb;

$pph_requests_table = $wpdb->prefix . 'pph_requests';
$pph_items_table    = $wpdb->prefix . 'pph_request_items';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Truncating this plugin's own tables by their Schema-built names.
$pph_removed['requests'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pph_requests_table}" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
$wpdb->query( "TRUNCATE TABLE {$pph_items_table}" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
$wpdb->query( "TRUNCATE TABLE {$pph_requests_table}" );

// Settings and setup state are deliberately left alone: a developer who ran
// the wizard by hand probably wants the store still configured afterwards.
echo 'Removed: ' . wp_json_encode( $pph_removed ) . "\n";
echo "Left in place: pph_settings and pph_setup_state.\n";
