<?php
/**
 * What EligibilityResolver needs to know about past requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Requests\Request;

/**
 * The slice of request history the cap and cooldown checks — and the
 * pending-cancellation timeline overlay — read.
 *
 * `EligibilityResolver` depends on this rather than on
 * `Requests\RequestRepository` directly, for one reason: the repository talks
 * to `$wpdb` and only runs against a real database, which would drag the
 * exhaustive eligibility matrix — meant to be a fast, no-bootstrap unit test —
 * into the integration suite. `RequestRepository` implements this interface
 * so production wiring costs nothing extra. `Requests\PendingCancellationBranch`
 * depends on it for the same reason, even though it too lives in Requests/ —
 * an interface is owned by whoever first needed it, not by a namespace.
 *
 * @since 0.7.0
 */
interface RequestHistory {

	/**
	 * How many requests of one type exist for an order, regardless of status.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type, e.g. `Request::TYPE_CANCELLATION`.
	 * @return int
	 */
	public function count_for_order( int $order_id, string $type ): int;

	/**
	 * The most recent request of one type raised against an order, if any.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type, e.g. `Request::TYPE_CANCELLATION`.
	 * @return Request|null
	 */
	public function most_recent_for_order( int $order_id, string $type ): ?Request;

	/**
	 * The pending request of one type raised against an order, if any.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type, e.g. `Request::TYPE_CANCELLATION`.
	 * @return Request|null
	 */
	public function pending_for_order( int $order_id, string $type ): ?Request;
}
