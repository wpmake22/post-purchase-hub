<?php
/**
 * Request-history test double.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PostPurchaseHub\Actions\RequestHistory;
use PostPurchaseHub\Requests\Request;

/**
 * An in-memory stand-in, so EligibilityResolver's cap and cooldown checks can
 * be tested without RequestRepository's real `$wpdb` dependency.
 *
 * @since 0.7.0
 */
final class FakeRequestHistory implements RequestHistory {

	/**
	 * Requests to serve, keyed by "{order_id}:{type}".
	 *
	 * @var array<string, list<Request>>
	 */
	public array $requests = array();

	/**
	 * Records one fake request, newest-first order preserved by insertion order.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $order_id   Order id.
	 * @param string $type       Request type.
	 * @param string $created_at UTC `Y-m-d H:i:s` creation time.
	 * @param string $status     One of Request::statuses().
	 * @return void
	 */
	public function add( int $order_id, string $type, string $created_at, string $status = Request::STATUS_PENDING ): void {
		$key = $order_id . ':' . $type;

		$this->requests[ $key ][] = Request::from_row(
			array(
				'id'         => count( $this->requests[ $key ] ?? array() ) + 1,
				'order_id'   => $order_id,
				'type'       => $type,
				'status'     => $status,
				'source'     => Request::SOURCE_ACCOUNT,
				'created_at' => $created_at,
				'updated_at' => $created_at,
			)
		);
	}

	/**
	 * Counts the fake requests recorded for an order and type.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type.
	 * @return int
	 */
	public function count_for_order( int $order_id, string $type ): int {
		return count( $this->requests[ $order_id . ':' . $type ] ?? array() );
	}

	/**
	 * The most recently added fake request for an order and type.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type.
	 * @return Request|null
	 */
	public function most_recent_for_order( int $order_id, string $type ): ?Request {
		$list = $this->requests[ $order_id . ':' . $type ] ?? array();

		return array() === $list ? null : $list[ array_key_last( $list ) ];
	}

	/**
	 * The most recently added fake request still pending, for an order and type.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Request type.
	 * @return Request|null
	 */
	public function pending_for_order( int $order_id, string $type ): ?Request {
		$list = array_reverse( $this->requests[ $order_id . ':' . $type ] ?? array() );

		foreach ( $list as $request ) {
			if ( Request::STATUS_PENDING === $request->status ) {
				return $request;
			}
		}

		return null;
	}
}
