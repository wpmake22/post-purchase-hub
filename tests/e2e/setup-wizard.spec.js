/**
 * Setup wizard end-to-end tests.
 *
 * The wizard is the one flow with a hard behavioural promise attached: until it
 * finishes, this plugin renders nothing on the storefront. So the first test
 * here is a negative one — a customer's order page before setup — and the rest
 * are about a merchant getting through four questions and being able to walk
 * away halfway without losing anything.
 *
 * Not executed in the session that wrote it: this environment has no wp-env
 * (no Docker, and the local site's database is down), so `npm run test:e2e`
 * could not be run here. Written to the conventions of the specs beside it —
 * `data-pph-*` selectors only, state seeded through WP-CLI rather than assumed
 * — so it runs as soon as an environment is available. See the milestone
 * report's Tests section.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");

const WIZARD = "[data-pph-wizard]";
const PROGRESS = "[data-pph-wizard-progress]";
const CONTINUE = "[data-pph-wizard-continue]";
const SKIP = "[data-pph-wizard-skip]";
const TIMELINE = "[data-pph-timeline]";
const HEALTH_SETUP = '[data-pph-health-row="setup"]';

/**
 * Puts the store back to a freshly installed state.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @return {Promise<void>}
 */
async function resetSetup(requestUtils) {
	await requestUtils.rest({
		method: "POST",
		path: "/wp-cli/v1/run",
		data: {
			command: "option delete pph_setup_state pph_settings",
		},
	});
}

/**
 * Seeds one paid order owned by the logged-in admin, so the storefront has
 * something to render once the wizard is done.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @return {Promise<string>} The seeded order id.
 */
async function seedOrder(requestUtils) {
	const php = [
		"$product = new WC_Product_Simple(); $product->set_name('Filter papers'); $product->set_regular_price('4.00'); $product->save();",
		"$order = wc_create_order( array( 'customer_id' => 1 ) );",
		"$order->add_product( $product, 1 );",
		"$order->set_billing_email('admin@example.com');",
		"$order->set_status('processing');",
		"$order->calculate_totals();",
		"$order->save();",
		"echo $order->get_id();",
	].join(" ");

	const { stdout } = await requestUtils.rest({
		method: "POST",
		path: "/wp-cli/v1/run",
		data: { command: `eval "${php}"` },
	});

	return stdout.trim();
}

test.describe("Setup wizard", () => {
	test.beforeEach(async ({ requestUtils }) => {
		await resetSetup(requestUtils);
	});

	test("renders nothing on the customer's order page before setup", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const orderId = await seedOrder(requestUtils);

		await page.goto(`/my-account/view-order/${orderId}/`);

		// The order page itself still works — it is WooCommerce's, not ours.
		await expect(page.locator("body")).toContainText("Order");

		// None of ours is on it, and no raw shortcode text either.
		await expect(page.locator(TIMELINE)).toHaveCount(0);
		await expect(page.locator("[data-pph-actions]")).toHaveCount(0);
		await expect(page.locator("body")).not.toContainText("[pph_");

		await admin.visitAdminPage("admin.php", "page=pph-settings");
		await expect(page.locator(HEALTH_SETUP)).toContainText("Not finished");
	});

	test("completes in four questions and brings the storefront up", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const orderId = await seedOrder(requestUtils);

		await admin.visitAdminPage("admin.php", "page=pph-setup");
		await expect(page.locator(WIZARD)).toBeVisible();
		await expect(page.locator(PROGRESS)).toBeVisible();

		// Step 1: statuses. Step 2: handling time. Step 3: tracking, which is
		// informational. Step 4: display mode. Then the actions screen.
		await page.click(CONTINUE);
		await page.fill('[name="pph_settings[eta_handling_days]"]', "2");
		await page.click(CONTINUE);
		await page.click(CONTINUE);
		await page.click(CONTINUE);
		await page.click(CONTINUE);

		await expect(page.locator(HEALTH_SETUP)).toContainText("Complete");

		await page.goto(`/my-account/view-order/${orderId}/`);
		await expect(page.locator(TIMELINE)).toBeVisible();
	});

	test("resumes on the step it was abandoned on, with answers intact", async ({
		page,
		admin,
	}) => {
		await admin.visitAdminPage("admin.php", "page=pph-setup");

		await page.click(CONTINUE);
		await page.fill('[name="pph_settings[eta_handling_days]"]', "5");
		await page.click(CONTINUE);

		// Abandon: leave for an unrelated screen, then come back to the wizard
		// with no step in the URL at all.
		await admin.visitAdminPage("index.php");
		await admin.visitAdminPage("admin.php", "page=pph-setup");

		await expect(page.locator(WIZARD)).toHaveAttribute(
			"data-pph-wizard-step",
			"3",
		);

		// The answer from step 2 is still there when the merchant goes back.
		await page.click("[data-pph-wizard-back]");
		await expect(
			page.locator('[name="pph_settings[eta_handling_days]"]'),
		).toHaveValue("5");
	});

	test("can be finished entirely by skipping, and still goes live", async ({
		page,
		admin,
	}) => {
		await admin.visitAdminPage("admin.php", "page=pph-setup");

		for (let step = 1; step <= 5; step++) {
			await page.click(SKIP);
		}

		await expect(page.locator(HEALTH_SETUP)).toContainText("Complete");
	});

	test("guest access cannot be enabled without the acknowledgement", async ({
		page,
		admin,
	}) => {
		await admin.visitAdminPage("admin.php", "page=pph-settings&tab=guest");

		// Tick "enabled" only, leaving the acknowledgement unticked.
		await page.check('[name="pph_settings[guest_lookup_enabled]"]');
		await page.click("input[type=submit], button[type=submit]");

		await expect(
			page.locator('[name="pph_settings[guest_lookup_enabled]"]'),
		).not.toBeChecked();
	});
});
