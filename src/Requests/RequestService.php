<?php
/**
 * Lifecycle operations shared by every request type.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Actions\RequestLifecycle;

/**
 * Creates and withdraws requests, and carries what every type shares: the
 * order note, the lifecycle hooks, the withdrawal rule.
 *
 * What is specific to one action — its own eligibility rule, its own reason
 * codes, what its render payload looks like — stays with that action
 * (`Actions\Cancel` for cancellation). This class only ever receives a
 * fully-decided row to create; it does not decide whether creating it is
 * allowed.
 *
 * Implements Actions\RequestLifecycle so Cancel and RequestsController can
 * depend on that instead of this concrete class — see that interface for why.
 *
 * @since 0.8.0
 */
final class RequestService implements RequestLifecycle {

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
		do_action( 'pph_request_created', $request, $order );

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
			do_action( 'pph_request_withdrawn', $request );
		}

		return $updated;
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
			'pph_request_note',
			sprintf(
				/* translators: 1: request type label, 2: request id. */
				__( 'Customer requested %1$s (request #%2$d).', 'post-purchase-hub' ),
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
				return __( 'a cancellation', 'post-purchase-hub' );
			case Request::TYPE_RETURN:
				return __( 'a return', 'post-purchase-hub' );
			case Request::TYPE_HELP:
				return __( 'help', 'post-purchase-hub' );
			default:
				return __( 'a request', 'post-purchase-hub' );
		}
	}
}
