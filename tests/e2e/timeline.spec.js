/**
 * Customer-facing timeline end-to-end tests.
 *
 * Every selector here is a plugin-owned `data-pph-*` attribute. Themes rename
 * and restructure the markup around the My Account templates freely, so a test
 * anchored on a theme's class names tells you which theme changed, not whether
 * the plugin works.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");
const { completeSetup } = require("./utils/setup");

const TIMELINE = "[data-pph-timeline]";
const SUMMARY = "[data-pph-timeline-summary]";
const STAGE = "[data-pph-stage]";

test.describe("Order timeline", () => {
	test.beforeEach(async ({ requestUtils }) => {
		await requestUtils.activatePlugin("post-purchase-hub");
		await completeSetup(requestUtils);
	});

	test("the orders list shows a progress cell per order", async ({
		page,
		admin,
	}) => {
		await admin.visitAdminPage("index.php");
		await page.goto("/my-account/orders/");

		const rows = page.locator("tbody tr");
		const summaries = page.locator(SUMMARY);

		await expect(summaries).toHaveCount(await rows.count());
		await expect(summaries.first()).toHaveAttribute("data-pph-stage", /.+/);
	});

	test("the order detail page shows an ordered list of stages", async ({
		page,
	}) => {
		await page.goto("/my-account/orders/");
		await page.locator("tbody tr a").first().click();

		const timeline = page.locator(TIMELINE);

		await expect(timeline).toBeVisible();
		await expect(timeline.locator("ol")).toBeVisible();

		// Every stage states its condition in words, not by colour alone.
		const stages = timeline.locator(STAGE);
		const count = await stages.count();

		expect(count).toBeGreaterThan(0);

		for (let i = 0; i < count; i++) {
			await expect(
				stages.nth(i).locator("[data-pph-stage-state-label]"),
			).not.toBeEmpty();
		}
	});

	test("the timeline is reachable and readable with the keyboard alone", async ({
		page,
	}) => {
		await page.goto("/my-account/orders/");

		// Tab to the first order link and follow it without touching the mouse.
		await page.keyboard.press("Tab");

		const link = page.locator("tbody tr a").first();
		await link.focus();
		await page.keyboard.press("Enter");

		const timeline = page.locator(TIMELINE);
		await expect(timeline).toBeVisible();

		// The section is labelled, so a screen reader announces what it is.
		const labelledBy = await timeline.getAttribute("aria-labelledby");
		expect(labelledBy).toBeTruthy();
		await expect(page.locator(`#${labelledBy}`)).toBeVisible();

		// Nothing inside the timeline is a focus trap: it holds no controls yet.
		await expect(timeline.locator("a, button, input")).toHaveCount(0);
	});

	test("the timeline renders without horizontal overflow", async ({
		page,
	}) => {
		await page.goto("/my-account/orders/");
		await page.locator("tbody tr a").first().click();

		const overflows = await page.evaluate(() => {
			const el = document.querySelector("[data-pph-timeline]");

			return el
				? el.scrollWidth > document.documentElement.clientWidth
				: false;
		});

		expect(overflows).toBe(false);
	});

	test("the timeline looks right", async ({ page }, testInfo) => {
		await page.goto("/my-account/orders/");
		await page.locator("tbody tr a").first().click();

		await expect(page.locator(TIMELINE)).toHaveScreenshot(
			`timeline-${testInfo.project.name}.png`,
		);
	});
});
