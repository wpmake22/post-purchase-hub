/**
 * Order fixtures for the end-to-end suite.
 *
 * Orders are made through WP-CLI rather than the REST API because the store
 * API needs a cart and a checkout, and none of these specs are about
 * purchasing — they are about what the plugin shows once an order exists.
 *
 * Every order is tagged `_pph_e2e_fixture` so a spec can clean up after itself
 * without guessing which orders belong to it.
 */

const FIXTURE_META = "_pph_e2e_fixture";

/**
 * Runs PHP through WP-CLI and returns its stdout.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @param {string} php          PHP to evaluate.
 * @return {Promise<string>}
 */
async function evalPhp(requestUtils, php) {
	const { stdout } = await requestUtils.rest({
		method: "POST",
		path: "/wp-cli/v1/run",
		data: { command: `eval "${php.replace(/"/g, '\\"')}"` },
	});

	return String(stdout || "").trim();
}

/**
 * Creates an order owned by the current test customer.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @param {string} status       Unprefixed order status, e.g. `processing`.
 * @param {string} email        Billing email to put on the order.
 * @return {Promise<{id: number, number: string}>}
 */
async function createOrder(requestUtils, status, email = "e2e@example.test") {
	const php = `
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
		$product = wc_get_product( $ids[0] );
		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->set_address( array( 'first_name' => 'E2E', 'last_name' => 'Customer', 'email' => '${email}', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => 'T35 7ST', 'country' => 'GB' ), 'billing' );
		$order->update_meta_data( '${FIXTURE_META}', '1' );
		$order->calculate_totals();
		$order->set_status( '${status}' );
		$order->save();
		echo $order->get_id() . '|' . $order->get_order_number();
	`.replace(/\s+/g, " ");

	const [id, number] = (await evalPhp(requestUtils, php)).split("|");

	return { id: Number(id), number };
}

/**
 * Deletes every order this suite created.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @return {Promise<void>}
 */
async function deleteFixtureOrders(requestUtils) {
	const php = `
		$orders = wc_get_orders( array( 'limit' => -1, 'meta_key' => '${FIXTURE_META}', 'meta_value' => '1', 'return' => 'ids', 'status' => 'any' ) );
		foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
		global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}pph_requests" );
		echo count( $orders );
	`.replace(/\s+/g, " ");

	await evalPhp(requestUtils, php);
}

/**
 * Reads one order's status straight from the database.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @param {number} orderId      Order id.
 * @return {Promise<string>}
 */
async function orderStatus(requestUtils, orderId) {
	return evalPhp(requestUtils, `echo wc_get_order( ${orderId} )->get_status();`);
}

/**
 * How many refunds an order carries. Must always be zero: this plugin issues
 * none in 1.0 (CLAUDE.md hard rule 8), and an approval is the one place that
 * could regress into issuing one.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @param {number} orderId      Order id.
 * @return {Promise<number>}
 */
async function refundCount(requestUtils, orderId) {
	return Number(
		await evalPhp(
			requestUtils,
			`echo count( wc_get_order( ${orderId} )->get_refunds() );`,
		),
	);
}

module.exports = {
	FIXTURE_META,
	evalPhp,
	createOrder,
	deleteFixtureOrders,
	orderStatus,
	refundCount,
};
