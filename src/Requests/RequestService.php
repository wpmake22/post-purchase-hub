<?php
/**
 * Lifecycle operations shared by every request type.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Actions\RequestLifecycle;
use PostPurchaseHub\Admin\RequestResolution;
use PostPurchaseHub\Security\Sanitizer;

/**
 * Creates, withdraws and resolves requests, and carries what every type
 * shares: the order note, the lifecycle hooks, the withdrawal rule.
 *
 * What is specific to one action — its own eligibility rule, its own reason
 * codes, what its render payload looks like — stays with that action
 * (`Actions\Cancel` for cancellation). This class only ever receives a
 * fully-decided row to create; it does not decide whether creating it is
 * allowed, and it does not decide whether an order may transition — that is
 * `Actions\Cancel::approve()`'s job. This class only ever updates the
 * request row itself.
 *
 * Implements Actions\RequestLifecycle so Cancel and RequestsController can
 * depend on that instead of this concrete class, and Admin\RequestResolution
 * so RequestActionController can do the same for admin resolution — see
 * either interface for why.
 *
 * @since 0.8.0
 */
final class RequestService implements RequestLifecycle, RequestResolution {

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param RequestRepository $requests Persistence.
	 */
	public function __construct( private RequestRepository $requests ) {}

	/**
	 * Finds one request by id.
	 *
	 * @since 0.8.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request {
		return $this->requests->find( $id );
	}

	/**
	 * Creates a request, writes its order note, and fires the lifecycle hook.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $data Column values. `status`, `created_at` and `updated_at` are supplied by the repository.
	 * @return Request
	 * @throws \RuntimeException When the row cannot be written or re-read (propagated from RequestRepository, or raised directly here if re-reading it fails).
	 */
	public function create( array $data ): Request {
		$id      = $this->requests->create( $data );
		$request = $this->requests->find( $id );

		if ( null === $request ) {
			// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
			throw new \RuntimeException( esc_html( 'Request ' . $id . ' could not be re-read after being created.' ) );
		}

		$order = wc_get_order( $request->order_id );
		$order = $order instanceof \WC_Order ? $order : null;

		if ( null !== $order ) {
			$order->add_order_note( $this->note_text( $request ), 0, false );
		}

		/**
		 * Fires after a customer request is created.
		 *
		 * The extension point M10's email classes trigger from: a customer's
		 * "request received" email and the merchant's "new request" email both
		 * hook this rather than this service knowing anything about either.
		 *
		 * @since 0.8.0
		 *
		 * @param Request        $request Request just created.
		 * @param \WC_Order|null $order   Order it was raised against, if it still resolves.
		 */
		do_action( 'wpmphub_request_created', $request, $order );

		return $request;
	}

	/**
	 * Withdraws a pending request.
	 *
	 * @since 0.8.0
	 *
	 * @param Request $request Request to withdraw.
	 * @return bool True when the request was pending and is now withdrawn.
	 */
	public function withdraw( Request $request ): bool {
		if ( ! $request->is_open() ) {
			return false;
		}

		$updated = $this->requests->update(
			$request->id,
			array(
				'status'      => Request::STATUS_WITHDRAWN,
				'resolved_at' => gmdate( RequestQuery::DATE_FORMAT ),
			)
		);

		if ( $updated ) {
			/**
			 * Fires after a customer withdraws their own pending request.
			 *
			 * @since 0.8.0
			 *
			 * @param Request $request Request that was withdrawn.
			 */
			do_action( 'wpmphub_request_withdrawn', $request );
		}

		return $updated;
	}

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
	public function approve( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool {
		$updated = $this->persist( $request, Request::STATUS_APPROVED, $resolved_by, $admin_note );

		if ( $updated ) {
			/**
			 * Fires after a staff member approves a customer's request.
			 *
			 * The extension point M10's "request approved" customer email
			 * triggers from.
			 *
			 * @since 0.9.0
			 *
			 * @param Request        $request Request just approved.
			 * @param \WC_Order|null $order   Order it was raised against, if it still resolves.
			 */
			do_action( 'wpmphub_request_approved', $request, $order );
		}

		return $updated;
	}

	/**
	 * Declines a pending request. Never touches the order this request was
	 * raised against — a decline has no order-side effect to undo.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to decline.
	 * @param \WC_Order|null $order       Order it was raised against, for the lifecycle hook.
	 * @param int            $resolved_by User id of the declining staff member.
	 * @param string|null    $admin_note  Internal note, if one was given.
	 * @return bool True when the request was open and is now declined.
	 */
	public function decline( Request $request, ?\WC_Order $order, int $resolved_by, ?string $admin_note = null ): bool {
		$updated = $this->persist( $request, Request::STATUS_DECLINED, $resolved_by, $admin_note );

		if ( $updated ) {
			/**
			 * Fires after a staff member declines a customer's request.
			 *
			 * The extension point M10's "request declined" customer email
			 * triggers from.
			 *
			 * @since 0.9.0
			 *
			 * @param Request        $request Request just declined.
			 * @param \WC_Order|null $order   Order it was raised against, if it still resolves.
			 */
			do_action( 'wpmphub_request_declined', $request, $order );
		}

		return $updated;
	}

	/**
	 * Closes a request as completed by reconciliation.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request     Request to close.
	 * @param \WC_Order|null $order       Order it was raised against, for the lifecycle hook.
	 * @param int            $resolved_by User id present when the reconciliation was noticed, 0 when none was.
	 * @param string         $note        Reconciliation note explaining why no transition happened here.
	 * @return bool True when the request was open and is now completed.
	 */
	public function complete( Request $request, ?\WC_Order $order, int $resolved_by, string $note ): bool {
		$updated = $this->persist( $request, Request::STATUS_COMPLETED, $resolved_by, $note );

		if ( $updated ) {
			/**
			 * Fires after a request is closed by reconciliation rather than
			 * by an explicit admin decision — the order it belongs to reached
			 * `cancelled` through some other route while this request was
			 * still open.
			 *
			 * @since 0.9.0
			 *
			 * @param Request        $request Request just closed.
			 * @param \WC_Order|null $order   Order it was raised against, if it still resolves.
			 */
			do_action( 'wpmphub_request_reconciled', $request, $order );
		}

		return $updated;
	}

	/**
	 * The reconciliation note text used when an order is cancelled by a route
	 * other than this request's own approval.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public static function reconciliation_note(): string {
		/**
		 * Filters the note stored on a request closed by reconciliation.
		 *
		 * @since 0.9.0
		 *
		 * @param string $note Default note text.
		 */
		return (string) apply_filters(
			'wpmphub_request_reconciliation_note',
			__( 'Order was cancelled through another route while this request was still open; closed automatically, no separate transition made.', 'wpmake-post-purchase-hub' )
		);
	}

	/**
	 * Writes a resolution to the request row, common to approve(), decline()
	 * and complete().
	 *
	 * @since 0.9.0
	 *
	 * @param Request     $request     Request to resolve.
	 * @param string      $status      Target status.
	 * @param int         $resolved_by User id resolving it, 0 when none applies.
	 * @param string|null $note        Internal note, if one was given.
	 * @return bool True when the request was open and the row changed.
	 */
	private function persist( Request $request, string $status, int $resolved_by, ?string $note ): bool {
		if ( ! $request->is_open() ) {
			return false;
		}

		$changes = array(
			'status'      => $status,
			'resolved_at' => gmdate( RequestQuery::DATE_FORMAT ),
			'resolved_by' => $resolved_by,
		);

		if ( null !== $note ) {
			$changes['admin_note'] = Sanitizer::note( $note );
		}

		return $this->requests->update( $request->id, $changes );
	}

	/**
	 * The order note text for a newly created request.
	 *
	 * Merchant-visible only: hard rule 1 keeps this off the customer's own
	 * account page, since the customer's confirmation is the dedicated email
	 * and the timeline's own branch state, not a generic order note.
	 *
	 * @since 0.8.0
	 *
	 * @param Request $request Request just created.
	 * @return string
	 */
	private function note_text( Request $request ): string {
		/**
		 * Filters the order note written when a customer request is created.
		 *
		 * @since 0.8.0
		 *
		 * @param string  $note    Default note text.
		 * @param Request $request Request just created.
		 */
		return (string) apply_filters(
			'wpmphub_request_note',
			sprintf(
				/* translators: 1: request type label, 2: request id. */
				__( 'Customer requested %1$s (request #%2$d).', 'wpmake-post-purchase-hub' ),
				self::type_label( $request->type ),
				$request->id
			),
			$request
		);
	}

	/**
	 * A human label for a request type.
	 *
	 * @since 0.8.0
	 *
	 * @param string $type One of Request::types().
	 * @return string
	 */
	private static function type_label( string $type ): string {
		switch ( $type ) {
			case Request::TYPE_CANCELLATION:
				return __( 'a cancellation', 'wpmake-post-purchase-hub' );
			case Request::TYPE_RETURN:
				return __( 'a return', 'wpmake-post-purchase-hub' );
			case Request::TYPE_HELP:
				return __( 'help', 'wpmake-post-purchase-hub' );
			default:
				return __( 'a request', 'wpmake-post-purchase-hub' );
		}
	}
}
