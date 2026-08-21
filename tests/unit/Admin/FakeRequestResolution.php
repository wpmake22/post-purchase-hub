<?php
/**
 * Request-resolution test double.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PostPurchaseHub\Admin\RequestResolution;
use PostPurchaseHub\Requests\Request;

/**
 * An in-memory stand-in, so RequestActionController can be tested without
 * RequestRepository's real `$wpdb` dependency.
 *
 * @since 0.9.0
 */
final class FakeRequestResolution implements RequestResolution {

	/**
	 * Requests, keyed by id.
	 *
	 * @var array<int, Request>
	 */
	public array $requests = array();

	/**
	 * Calls recorded by approve(), in call order.
	 *
	 * @var list<array{request: Request, order: \WC_Order|null, resolved_by: int, admin_note: string|null}>
	 */
	public array $approved = array();

	/**
	 * Calls recorded by decline(), in call order.
	 *
	 * @var list<array{request: Request, order: \WC_Order|null, resolved_by: int, admin_note: string|null}>
	 */
	public array $declined = array();

	/**
	 * Calls recorded by complete(), in call order.
	 *
	 * @var list<array{request: Request, order: \WC_Order|null, resolved_by: int, note: string}>
	 */
	public array $completed = array();

	/**
	 * Seeds a request for find() to serve.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $row Column values; missing ones get sane defaults.
	 * @return Request
	 */
	public function seed( array $row ): Request {
		$request = Request::from_row(
			array_merge(
				array(
					'id'         => count( $this->requests ) + 1,
					'type'       => Request::TYPE_CANCELLATION,
					'status'     => Request::STATUS_PENDING,
					'source'     => Request::SOURCE_ACCOUNT,
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				),
				$row
			)
		);

		$this->requests[ $request->id ] = $request;

		return $request;
	}

	/**
	 * Finds a seeded request by id.
	 *
	 * @since 0.9.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request {
		return $this->requests[ $id ] ?? null;
	}

	/**
	 * Records the call and marks the request approved, unless it was not open.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to approve.
	 * @param \WC_Order|null $order       Order it was raised against.
	 * @param int            $resolved_by Approving user id.
	 * @param string|null    $admin_note  Internal note, if one was given.
	 * @return bool
	 */
	public function approve( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool {
		$this->approved[] = array(
			'request'     => $request,
			'order'       => $order,
			'resolved_by' => $resolved_by,
			'admin_note'  => $admin_note,
		);

		return $this->transition( $request, Request::STATUS_APPROVED );
	}

	/**
	 * Records the call and marks the request declined, unless it was not open.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to decline.
	 * @param \WC_Order|null $order       Order it was raised against.
	 * @param int            $resolved_by Declining user id.
	 * @param string|null    $admin_note  Internal note, if one was given.
	 * @return bool
	 */
	public function decline( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool {
		$this->declined[] = array(
			'request'     => $request,
			'order'       => $order,
			'resolved_by' => $resolved_by,
			'admin_note'  => $admin_note,
		);

		return $this->transition( $request, Request::STATUS_DECLINED );
	}

	/**
	 * Records the call and marks the request completed, unless it was not open.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to close.
	 * @param \WC_Order|null $order       Order it was raised against.
	 * @param int            $resolved_by User id present when the reconciliation was noticed.
	 * @param string         $note        Reconciliation note.
	 * @return bool
	 */
	public function complete( Request $request, ?\WC_Order $order, int $resolved_by, string $note ): bool {
		$this->completed[] = array(
			'request'     => $request,
			'order'       => $order,
			'resolved_by' => $resolved_by,
			'note'        => $note,
		);

		return $this->transition( $request, Request::STATUS_COMPLETED );
	}

	/**
	 * Updates the seeded request's status in place, mirroring RequestService's
	 * own "no-op unless open" rule.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $request Request to transition.
	 * @param string  $status  Target status.
	 * @return bool
	 */
	private function transition( Request $request, string $status ): bool {
		if ( ! $request->is_open() ) {
			return false;
		}

		$this->requests[ $request->id ] = Request::from_row(
			array_merge( $request->to_array(), array( 'status' => $status ) )
		);

		return true;
	}
}
