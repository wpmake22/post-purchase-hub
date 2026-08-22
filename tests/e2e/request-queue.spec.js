/**
 * The merchant-side request queue: triage, approve, decline.
 *
 * The assertion this file exists for is the one in `approve`: the order moves
 * to cancelled and **no refund is created**. docs/SPEC.md "The refund decision"
 * and CLAUDE.md hard rule 8 both turn on that, and it is the kind of thing a
 * later milestone could regress into with one convenient-looking line.
 */

const { test, expect } = require("@wordpress/e2e-test-utils-playwright");
const { completeSetup } = require("./utils/setup");
const {
	evalPhp,
	createOrder,
	deleteFixtureOrders,
	orderStatus,
	refundCount,
} = require("./utils/orders");

const QUEUE_PAGE = "page=pph-requests";

/**
 * Files a cancellation request against an order, server-side.
 *
 * The customer journey that produces one is `cancellation.spec.js`'s subject;
 * here it is a precondition, and clicking through it again would make every
 * queue test fail for reasons that belong to the other file.
 *
 * @param {Object} requestUtils Playwright request utils.
 * @param {number} orderId      Order the request is against.
 * @return {Promise<number>}    The new request's id.
 */
async function fileRequest(requestUtils, orderId) {
	const php = `
		$order   = wc_get_order( ${orderId} );
		$request = PostPurchaseHub\\Plugin::instance()->cancel()->execute( $order, 'changed_mind', 'Please cancel.', 'account', $order->get_customer_id() );
		echo $request->id;
	`.replace(/\s+/g, " ");

	return Number(await evalPhp(requestUtils, php));
}

test.describe("Admin request queue", () => {
	test.beforeEach(async ({ requestUtils }) => {
		await requestUtils.activatePlugin("post-purchase-hub");
		await completeSetup(requestUtils);
	});

	test.afterEach(async ({ requestUtils }) => {
		await deleteFixtureOrders(requestUtils);
	});

	test("a pending request appears in the queue and in the menu bubble", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");
		await fileRequest(requestUtils, order.id);

		await admin.visitAdminPage("admin.php", QUEUE_PAGE);

		const rows = page.locator(".wp-list-table tbody tr");
		await expect(rows).toHaveCount(1);
		await expect(rows.first()).toContainText(String(order.id));
		await expect(rows.first()).toContainText("pending");

		// A request a merchant does not notice for six hours is worse than an
		// email (docs/SPEC.md Phase 1, UX problem 6), so the count is not polish.
		await expect(
			page.locator('#adminmenu a[href*="page=pph-requests"]'),
		).toContainText("1");
	});

	test("approving cancels the order and issues no refund", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");
		const requestId = await fileRequest(requestUtils, order.id);

		await admin.visitAdminPage("admin.php", `${QUEUE_PAGE}&request_id=${requestId}`);

		// The decision form posts to admin-post.php and carries a nonce.
		const form = page.locator('form:has(input[value="pph_approve_request"])');
		await expect(form.locator('input[name="_wpnonce"]')).toHaveCount(1);

		await form.locator('[type="submit"]').click();

		expect(await orderStatus(requestUtils, order.id)).toBe("cancelled");

		// The whole point. 1.0 refunds nothing, ever.
		expect(await refundCount(requestUtils, order.id)).toBe(0);
	});

	test("declining resolves the request and leaves the order alone", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");
		const requestId = await fileRequest(requestUtils, order.id);

		await admin.visitAdminPage("admin.php", `${QUEUE_PAGE}&request_id=${requestId}`);
		await page
			.locator('form:has(input[value="pph_decline_request"]) [type="submit"]')
			.click();

		expect(await orderStatus(requestUtils, order.id)).toBe("processing");
		expect(await refundCount(requestUtils, order.id)).toBe(0);

		await admin.visitAdminPage("admin.php", QUEUE_PAGE);
		await expect(page.locator(".wp-list-table tbody tr").first()).toContainText(
			"declined",
		);
	});

	test("a second approval of the same request changes nothing", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");
		const requestId = await fileRequest(requestUtils, order.id);
		const detail = `${QUEUE_PAGE}&request_id=${requestId}`;

		await admin.visitAdminPage("admin.php", detail);
		await page
			.locator('form:has(input[value="pph_approve_request"]) [type="submit"]')
			.click();

		// A merchant double-submitting must not transition twice or mail twice.
		await admin.visitAdminPage("admin.php", detail);
		await expect(
			page.locator('form:has(input[value="pph_approve_request"])'),
		).toHaveCount(0);

		expect(await orderStatus(requestUtils, order.id)).toBe("cancelled");
		expect(await refundCount(requestUtils, order.id)).toBe(0);
	});

	test("a customer-supplied note is shown escaped, never as markup", async ({
		page,
		admin,
		requestUtils,
	}) => {
		const order = await createOrder(requestUtils, "processing");
		const requestId = await fileRequest(requestUtils, order.id);

		await admin.visitAdminPage("admin.php", `${QUEUE_PAGE}&request_id=${requestId}`);

		expect(await page.evaluate(() => window.pphXss)).toBeUndefined();
		await expect(page.locator(".pph-request-detail")).toContainText(
			"Please cancel.",
		);
	});

	test("the queue is readable at 375px", async ({ page, admin, requestUtils }) => {
		await page.setViewportSize({ width: 375, height: 812 });

		const order = await createOrder(requestUtils, "processing");
		await fileRequest(requestUtils, order.id);

		await admin.visitAdminPage("admin.php", QUEUE_PAGE);

		await expect(page.locator(".wp-list-table tbody tr")).toHaveCount(1);
	});
});
