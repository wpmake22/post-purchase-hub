/**
 * Guest order-lookup end-to-end tests.
 *
 * The journey a customer without an account actually takes: submit the form,
 * be told the same thing whatever happens, follow the emailed link, land on the
 * order with the token gone from the address bar.
 *
 * Every selector is a plugin-owned `data-wpmphub-*` attribute, per tests/e2e/README.
 *
 * Guest lookup is off until a merchant enables it and acknowledges what it
 * means (CLAUDE.md hard rule 15), so these specs set both flags through the CLI
 * rather than assuming a fresh install exposes the surface — which it must not.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");
const { completeSetup } = require("./utils/setup");

const FORM = "[data-wpmphub-lookup-form]";
const NOTICE = "[data-wpmphub-lookup-notice]";
const NUMBER = "[data-wpmphub-lookup-number]";
const EMAIL = "[data-wpmphub-lookup-email]";
const TIMELINE = "[data-wpmphub-timeline]";
const GUEST_ORDER = "[data-wpmphub-guest-order]";
const ACTIONS = "[data-wpmphub-actions]";

const LOOKUP_PAGE_TITLE = "Track my order";

/**
 * Writes the plugin's settings option.
 *
 * @param {Object}  requestUtils     Playwright request utils.
 * @param {boolean} guestLookupOn    Whether guest lookup is enabled.
 * @param {boolean} acknowledged     Whether the wizard's acknowledgement is recorded.
 */
async function setGuestLookup(requestUtils, guestLookupOn, acknowledged) {
	await requestUtils.rest({
		method: "POST",
		path: "/wp/v2/settings",
		data: {
			wpmphub_settings: {
				guest_lookup_enabled: guestLookupOn,
				guest_lookup_acknowledged: acknowledged,
			},
		},
	});
}

test.describe("Guest order lookup", () => {
	let lookupPage;

	test.beforeAll(async ({ requestUtils }) => {
		await requestUtils.activatePlugin("wpmake-post-purchase-hub-for-woocommerce");
		await completeSetup(requestUtils);

		lookupPage = await requestUtils.createPage({
			title: LOOKUP_PAGE_TITLE,
			content:
				"<!-- wp:shortcode -->[wpmphub_order_lookup]<!-- /wp:shortcode -->",
			status: "publish",
		});
	});

	test.afterAll(async ({ requestUtils }) => {
		await requestUtils.deleteAllPages();
	});

	test("the form is absent until a merchant enables guest lookup", async ({
		page,
		requestUtils,
	}) => {
		await setGuestLookup(requestUtils, false, false);
		await page.goto(lookupPage.link);

		await expect(page.locator(FORM)).toHaveCount(0);
	});

	test("enabling without the acknowledgement is still not enough", async ({
		page,
		requestUtils,
	}) => {
		await setGuestLookup(requestUtils, true, false);
		await page.goto(lookupPage.link);

		await expect(page.locator(FORM)).toHaveCount(0);
	});

	test.describe("once enabled", () => {
		test.beforeEach(async ({ requestUtils }) => {
			await setGuestLookup(requestUtils, true, true);
		});

		test("an order that does not exist is answered the same as one that does", async ({
			page,
		}) => {
			await page.goto(lookupPage.link);

			await page.locator(NUMBER).fill("99999999");
			await page.locator(EMAIL).fill("nobody@example.com");
			await page.locator("[data-wpmphub-lookup-submit]").click();

			const notice = page.locator(NOTICE);

			await expect(notice).toBeVisible();

			const missText = await notice.textContent();

			// The same submission shape against an order that does exist. The
			// fixture order's number and address come from the seeded data; what
			// is asserted is that the visitor cannot tell the two apart.
			await page.goto(lookupPage.link);
			await page.locator(NUMBER).fill("1");
			await page.locator(EMAIL).fill("nobody@example.com");
			await page.locator("[data-wpmphub-lookup-submit]").click();

			await expect(notice).toHaveText(missText.trim());
		});

		test("the submitted address never reaches the resulting URL", async ({
			page,
		}) => {
			await page.goto(lookupPage.link);

			await page.locator(NUMBER).fill("1");
			await page.locator(EMAIL).fill("customer@example.com");
			await page.locator("[data-wpmphub-lookup-submit]").click();

			await expect(page.locator(NOTICE)).toBeVisible();
			expect(page.url()).not.toContain("customer@example.com");
			expect(page.url()).not.toContain("customer");
		});

		test("the form is reachable at 375px and at 1440px", async ({
			page,
		}) => {
			for (const width of [375, 1440]) {
				await page.setViewportSize({ width, height: 900 });
				await page.goto(lookupPage.link);

				await expect(page.locator(NUMBER)).toBeVisible();
				await expect(page.locator(EMAIL)).toBeVisible();
				await expect(
					page.locator("[data-wpmphub-lookup-submit]"),
				).toBeVisible();
			}
		});
	});
});

test.describe("Signed order links", () => {
	test("the token is gone from the URL after the first navigation", async ({
		page,
		requestUtils,
	}) => {
		await requestUtils.activatePlugin("wpmake-post-purchase-hub-for-woocommerce");

		// A token minted by the plugin's own CLI, so the spec exercises the same
		// wire format the emails use rather than a hand-rolled one.
		const token = await requestUtils.cli(
			'wp eval "echo (new PostPurchaseHub\\\\Security\\\\TokenService())->issue( 1, wc_get_order( 1 )->get_order_key() );"',
		);

		await page.goto(`/my-account/view-order/1/?wpmphub_token=${token.trim()}`);

		expect(page.url()).not.toContain("wpmphub_token");
		expect(page.url()).toContain("wpmphub_context=ready");

		// The order itself, not a login form: the guest has no password.
		await expect(page.locator(GUEST_ORDER)).toBeVisible();
		await expect(page.locator(TIMELINE)).toBeVisible();
		await expect(page.locator("form.woocommerce-form-login")).toHaveCount(
			0,
		);
	});

	test("the order page offers the actions the customer is eligible for", async ({
		page,
		requestUtils,
	}) => {
		const token = await requestUtils.cli(
			'wp eval "echo (new PostPurchaseHub\\Security\\TokenService())->issue( 1, wc_get_order( 1 )->get_order_key() );"',
		);

		await page.goto(`/my-account/view-order/1/?wpmphub_token=${token.trim()}`);

		// Core's own order details table renders through the same
		// woocommerce_view_order hook this plugin's guest template re-fires.
		await expect(
			page.locator(".woocommerce-table--order-details"),
		).toBeVisible();
		await expect(page.locator(ACTIONS)).toBeVisible();
	});

	test("an expired token lands on a page that says so, not on the order", async ({
		page,
	}) => {
		await page.goto(
			"/my-account/view-order/1/?wpmphub_token=ZXhwaXJlZA." + "a".repeat(64),
		);

		expect(page.url()).not.toContain("wpmphub_token");
		expect(page.url()).toContain("wpmphub_context=expired");
		await expect(page.locator(TIMELINE)).toHaveCount(0);

		// Explained, not silently answered with a password prompt.
		await expect(
			page.locator(".woocommerce-notices-wrapper"),
		).toContainText(/expired|no longer valid/i);
	});
});
