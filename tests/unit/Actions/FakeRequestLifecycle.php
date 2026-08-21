<?php
/**
 * Request-lifecycle test double.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PostPurchaseHub\Actions\RequestLifecycle;
use PostPurchaseHub\Requests\Request;

/**
 * An in-memory stand-in, so Cancel and RequestsController can be tested
 * without RequestRepository's real `$wpdb` dependency.
 *
 * @since 0.8.0
 */
final class FakeRequestLifecycle implements RequestLifecycle {

	/**
	 * Requests, keyed by id.
	 *
	 * @var array<int, Request>
	 */
	public array $requests = array();

	/**
	 * Column data every create() call was given, in call order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $created = array();

	/**
	 * Requests every withdraw() call was given, in call order.
	 *
	 * @var list<Request>
	 */
	public array $withdrawn = array();

	/**
	 * Whether the next withdraw() call should report success.
	 *
	 * @var bool
	 */
	public bool $withdraw_result = true;

	/**
	 * Next id create() assigns.
	 *
	 * @var int
	 */
	private int $next_id = 1;

	/**
	 * Seeds a request as if already created, for find()/withdraw() to serve.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $row Column values; missing ones get sane defaults.
	 * @return Request
	 */
	public function seed( array $row ): Request {
		$request = Request::from_row(
			array_merge(
				array(
					'id'         => $this->next_id,
					'status'     => Request::STATUS_PENDING,
					'source'     => Request::SOURCE_ACCOUNT,
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				),
				$row
			)
		);

		$this->requests[ $request->id ] = $request;
		$this->next_id                  = max( $this->next_id, $request->id + 1 );

		return $request;
	}

	/**
	 * Finds a seeded or created request by id.
	 *
	 * @since 0.8.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request {
		return $this->requests[ $id ] ?? null;
	}

	/**
	 * Records the data create() was called with and returns a fake request for it.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return Request
	 */
	public function create( array $data ): Request {
		$this->created[] = $data;

		return $this->seed( $data );
	}

	/**
	 * Records the request withdraw() was called with.
	 *
	 * @since 0.8.0
	 *
	 * @param Request $request Request to withdraw.
	 * @return bool
	 */
	public function withdraw( Request $request ): bool {
		$this->withdrawn[] = $request;

		return $this->withdraw_result;
	}
}
