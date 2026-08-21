<?php
/**
 * What the admin queue needs to resolve a request.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Requests\Request;

/**
 * The write half of admin resolution, as seen from outside Requests/.
 *
 * `RequestActionController` depends on this rather than on
 * `Requests\RequestService` directly, for the same reason
 * `Actions\RequestLifecycle` exists: the concrete class ultimately reaches
 * `RequestRepository`, which needs a real `$wpdb` to do anything, and
 * depending on it directly would drag every capability and nonce test into
 * the integration suite. `RequestService` implements this so production
 * wiring costs nothing extra — an interface is owned by whoever first needed
 * it, not by a namespace, so this one lives here rather than in Requests/.
 *
 * @since 0.9.0
 */
interface RequestResolution {

	/**
	 * Finds one request by id.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request;

	/**
	 * Approves a pending request.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to approve.
	 * @param \WC_Order|null $order       Order it was raised against, for the lifecycle hook.
	 * @param int            $resolved_by User id of the approving staff member.
	 * @param string|null    $admin_note  Internal note, if one was given.
	 * @return bool True when the request was open and is now approved.
	 */
	public function approve( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool;

	/**
	 * Declines a pending request.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to decline.
	 * @param \WC_Order|null $order       Order it was raised against, for the lifecycle hook.
	 * @param int            $resolved_by User id of the declining staff member.
	 * @param string|null    $admin_note  Internal note, if one was given.
	 * @return bool True when the request was open and is now declined.
	 */
	public function decline( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool;

	/**
	 * Closes a request as completed by reconciliation — the order reached its
	 * resolution through some other route while this request was still open.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to close.
	 * @param \WC_Order|null $order       Order it was raised against, for the lifecycle hook.
	 * @param int            $resolved_by User id present when the reconciliation was noticed, 0 when none was.
	 * @param string         $note        Reconciliation note explaining why no transition happened here.
	 * @return bool True when the request was open and is now completed.
	 */
	public function complete( Request $request, ?\WC_Order $order, int $resolved_by, string $note ): bool;
}
