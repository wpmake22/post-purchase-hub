/**
 * Reorder end-to-end tests.
 *
 * Driven by a deliberately broken historical order — one line still buyable,
 * one out of stock, one whose product has been deleted — because the point of
 * this feature is what it says about the lines that cannot come back.
 *
 * The order is seeded through WP-CLI rather than assumed, so the three outcomes
 * under test are the three the fixture actually contains.
 *
 * Selectors are plugin-owned `data-pph-*` attributes, per tests/e2e/README. The
 * two exceptions are core WooCommerce classes (`a.order-again`, the empty-cart
 * message), asserted precisely because they belong to core rather than to us:
 * one must be gone, the other must still be true while the summary is on screen.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");

const SUMMARY = "[data-pph-reorder]";
const LINE = "[data-pph-reorder-line]";
const CONFIRM = "[data-pph-reorder-confirm]";
const REORDER_ACTION = '[data-pph-action="reorder"] a';

/**
 * Seeds a completed order for the logged-in admin containing one buyable line,
 * one out-of-stock line and one line whose product no longer exists.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @return {Promise<string>} The seeded order id.
 */
async function seedBrokenOrder(requestUtils) {
	const php = [
		"$in = new WC_Product_Simple(); $in->set_name('Espresso beans'); $in->set_regular_price('12.50'); $in->save();",
		"$out = new WC_Product_Simple(); $out->set_name('Ceramic cup'); $out->set_regular_price('9.00'); $out->set_stock_status('outofstock'); $out->save();",
		"$gone = new WC_Product_Simple(); $gone->set_name('Discontinued grinder'); $gone->set_regular_price('99.00'); $gone->save();",
		"$order = wc_create_order( array( 'customer_id' => 1 ) );",
		"$order->add_product( $in, 2 );",
		"$order->add_product( $out, 1 );",
		"$order->add_product( $gone, 1 );",
		"$order->set_billing_email('admin@example.com');",
		"$order->calculate_totals();",
		"$order->update_status('completed');",
		"wp_delete_post( $gone->get_id(), true );",
		"echo $order->get_id();",
	].join(" ");

	const id = await requestUtils.cli(`wp eval "${php.replace(/"/g, '\\"')}"`);

	return id.trim();
}

/**
 * Empties the cart between tests, since it lives in the browser's session.
 *
 * @param {Object} page Playwright page.
 */
async function emptyCart(page) {
	await page.goto("/cart/");

	const remove = page.locator("a.remove");

	while ((await remove.count()) > 0) {
		await remove.first().click();
		await page.waitForLoadState("networkidle");
	}
}

test.describe("Reorder", () => {
	let orderId;

	test.beforeAll(async ({ requestUtils }) => {
		await requestUtils.activatePlugin("post-purchase-hub");

		orderId = await seedBrokenOrder(requestUtils);
	});

	test.beforeEach(async ({ page }) => {
		await emptyCart(page);
	});

	test("the order page offers one reorder path, not two", async ({
		page,
	}) => {
		await page.goto(`/my-account/view-order/${orderId}/`);

		await expect(page.locator(REORDER_ACTION)).toBeVisible();

		// Core's own one-click Order again would bypass the summary entirely.
		await expect(page.locator("a.order-again")).toHaveCount(0);
	});

	test("the summary states every line and leaves the cart alone", async ({
		page,
	}) => {
		await page.goto(`/my-account/view-order/${orderId}/`);
		await page.locator(REORDER_ACTION).click();

		await expect(page.locator(SUMMARY)).toBeVisible();
		await expect(page.locator(LINE)).toHaveCount(3);

		await expect(
			page.locator('[data-pph-reorder-outcome="added"]'),
		).toHaveCount(1);
		await expect(
			page.locator('[data-pph-reorder-outcome="out_of_stock"]'),
		).toHaveCount(1);
		await expect(
			page.locator('[data-pph-reorder-outcome="unavailable"]'),
		).toHaveCount(1);

		// Nothing has been added yet, which is the whole claim of the screen.
		await page.goto("/cart/");
		await expect(
			page.locator(".wc-empty-cart-message, .cart-empty"),
		).toBeVisible();
	});

	test("confirming adds only the line that is still buyable", async ({
		page,
	}) => {
		await page.goto(
			`/my-account/view-order/${orderId}/?pph_reorder=${orderId}`,
		);

		await expect(page.locator(SUMMARY)).toBeVisible();
		await page.locator(CONFIRM).click();

		await page.waitForURL(/\/cart\/?$/);

		await expect(page.locator("td.product-name")).toHaveCount(1);
		await expect(page.locator("td.product-name")).toContainText(
			"Espresso beans",
		);
	});

	test("a second customer's order offers no reorder for this one", async ({
		page,
	}) => {
		// The summary is scoped to the order whose page it is on: asking for
		// another order's summary from this page must render nothing.
		await page.goto(
			`/my-account/view-order/${orderId}/?pph_reorder=999999`,
		);

		await expect(page.locator(SUMMARY)).toHaveCount(0);
	});

	test("the summary is usable at 375px and at 1440px", async ({ page }) => {
		for (const width of [375, 1440]) {
			await page.setViewportSize({ width, height: 900 });
			await page.goto(
				`/my-account/view-order/${orderId}/?pph_reorder=${orderId}`,
			);

			await expect(page.locator(SUMMARY)).toBeVisible();
			await expect(page.locator(CONFIRM)).toBeVisible();
		}
	});
});
