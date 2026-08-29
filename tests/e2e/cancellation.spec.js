/**
 * The customer-side cancellation journey.
 *
 * docs/MILESTONE-PROMPTS.md M16 names this run: request cancellation, receive
 * confirmation, see the pending state. It is the journey docs/SPEC.md Phase 9
 * has the strongest copy requirement on — a customer must never be told an
 * order is cancelled when what happened is that they asked.
 *
 * Every selector is a plugin-owned `data-wpmphub-*` attribute, never a theme's.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");
const { completeSetup } = require("./utils/setup");
const {
	createOrder,
	deleteFixtureOrders,
	orderStatus,
} = require("./utils/orders");

const ACTION = '[data-wpmphub-action="cancel"]';
const MODAL = "[data-wpmphub-request-modal]";
const REASON = "[data-wpmphub-request-reason]";
const NOTE = "[data-wpmphub-request-note]";
const SUBMIT = "[data-wpmphub-request-submit]";
const BRANCH = '[data-wpmphub-branch="cancellation_requested"]';

test.describe("Cancellation requests", () => {
	test.beforeEach(async ({ requestUtils }) => {
		await requestUtils.activatePlugin("wpmake-post-purchase-hub-for-woocommerce");
		await completeSetup(requestUtils);
	});

	test.afterEach(async ({ requestUtils }) => {
		await deleteFixtureOrders(requestUtils);
	});

	test("the action asks, and never claims the order is cancelled", async ({
		page,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");

		await page.goto(`/my-account/view-order/${order.id}/`);

		const action = page.locator(ACTION);
		await expect(action).toBeVisible();

		// Phase 9's copy rule, asserted rather than trusted: "request", never
		// a bare "cancel order" that reads as already done.
		await expect(action).toContainText(/request/i);
		await expect(action).not.toContainText(/^cancel order$/i);
	});

	test("requesting leaves a pending state and withdraws the action", async ({
		page,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");

		await page.goto(`/my-account/view-order/${order.id}/`);
		await page.locator(`${ACTION} a, ${ACTION} button`).click();

		const modal = page.locator(MODAL);
		await expect(modal).toBeVisible();
		await expect(modal).toHaveAttribute("role", "dialog");
		await expect(modal).toHaveAttribute("aria-modal", "true");

		await page.locator(REASON).selectOption("changed_mind");
		await page.locator(NOTE).fill("Please cancel this one.");
		await page.locator(SUBMIT).click();

		await page.reload();

		// The pending branch appears, and it sets an expectation rather than
		// leaving the customer wondering (Phase 9, UX problem 3).
		const branch = page.locator(BRANCH);
		await expect(branch).toBeVisible();
		await expect(branch).toContainText(/requested/i);

		// The action is gone, so the same request cannot be filed twice.
		await expect(page.locator(ACTION)).toHaveCount(0);

		// And the order itself has not moved: only a merchant can do that.
		expect(await orderStatus(requestUtils, order.id)).toBe("processing");
	});

	test("markup in the note never reaches the page as markup", async ({
		page,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");

		await page.goto(`/my-account/view-order/${order.id}/`);
		await page.locator(`${ACTION} a, ${ACTION} button`).click();
		await page.locator(REASON).selectOption("other");
		await page.locator(NOTE).fill("<script>window.wpmphubXss = true;</script>");
		await page.locator(SUBMIT).click();

		await page.reload();

		expect(await page.evaluate(() => window.wpmphubXss)).toBeUndefined();
		expect(await page.content()).not.toContain("<script>window.wpmphubXss");
	});

	test("an order in an ineligible status offers no cancellation at all", async ({
		page,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "completed");

		await page.goto(`/my-account/view-order/${order.id}/`);

		await expect(page.locator("[data-wpmphub-timeline]")).toBeVisible();
		await expect(page.locator(ACTION)).toHaveCount(0);
	});

	test("the modal opens, traps focus and returns it on Escape", async ({
		page,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");

		await page.goto(`/my-account/view-order/${order.id}/`);

		const trigger = page.locator(`${ACTION} a, ${ACTION} button`).first();
		await trigger.focus();
		await page.keyboard.press("Enter");

		const modal = page.locator(MODAL);
		await expect(modal).toBeVisible();

		// Focus is inside the dialog, so a keyboard user is not left behind it.
		expect(
			await page.evaluate(
				(sel) => document.querySelector(sel).contains(document.activeElement),
				MODAL,
			),
		).toBe(true);

		await page.keyboard.press("Escape");
		await expect(modal).toBeHidden();

		// And it comes back to where it was, not to the top of the document.
		await expect(trigger).toBeFocused();
	});

	test("the modal is usable at 375px", async ({ page, requestUtils }) => {
		await page.setViewportSize({ width: 375, height: 812 });

		const order = await createOrder(requestUtils, "processing");

		await page.goto(`/my-account/view-order/${order.id}/`);
		await page.locator(`${ACTION} a, ${ACTION} button`).click();

		await expect(page.locator(MODAL)).toBeVisible();
		await expect(page.locator(SUBMIT)).toBeVisible();

		// Nothing the modal adds may push the page sideways.
		const overflows = await page.evaluate(
			() => document.documentElement.scrollWidth > window.innerWidth + 1,
		);
		expect(overflows).toBe(false);
	});
});
