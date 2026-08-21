<?php
/**
 * What an action or the REST controller needs to create and withdraw requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Requests\Request;

/**
 * The write half of the request lifecycle, as seen from outside Requests/.
 *
 * `Actions\Cancel` and `Rest\RequestsController` depend on this rather than
 * on `Requests\RequestService` directly, for the same reason
 * `RequestHistory` exists: both ultimately reach `RequestRepository`, which
 * needs a real `$wpdb` to do anything, and depending on the concrete class
 * would drag every test of either into the integration suite. `RequestService`
 * implements this so production wiring costs nothing extra.
 *
 * @since 0.8.0
 */
interface RequestLifecycle {

	/**
	 * Finds one request by id, for the REST controller's withdrawal lookup.
	 *
	 * @since 0.8.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request;

	/**
	 * Creates a request, writing whatever side effects the real
	 * implementation attaches to that (an order note, a lifecycle hook).
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return Request
	 */
	public function create( array $data ): Request;

	/**
	 * Withdraws a pending request.
	 *
	 * @since 0.8.0
	 *
	 * @param Request $request Request to withdraw.
	 * @return bool True when the request was pending and is now withdrawn.
	 */
	public function withdraw( Request $request ): bool;
}
