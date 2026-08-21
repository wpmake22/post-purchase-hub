<?php
/**
 * Approve/decline handlers for the admin request queue.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestService;
use PostPurchaseHub\Support\Logger;

/**
 * `admin-post.php?action=pph_approve_request` and `pph_decline_request`.
 *
 * Every handler runs capability check, then nonce, then action — in that
 * order, per docs/SPEC.md Phase 8: a stale nonce on a request from a user who
 * should never have reached this page either way is still a 403, not a
 * "nonce expired, please retry" invitation. Both handlers are idempotent: a
 * request that is no longer open (already resolved, by this handler or by
 * `Plugin::reconcile_pending_cancellation()`) is a silent no-op rather than a
 * second transition or a second email.
 *
 * Approving never calls a refund API. It transitions the order via
 * `Actions\Cancel::approve()`, which owns that boundary — see its docblock.
 *
 * @since 0.9.0
 */
final class RequestActionController {

	/**
	 * Capability every handler requires.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'edit_shop_orders';

	/**
	 * Nonce action shared by both handlers' forms.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pph_request_action';

	/**
	 * The admin-post.php action name for approval.
	 *
	 * @var string
	 */
	public const APPROVE_ACTION = 'pph_approve_request';

	/**
	 * The admin-post.php action name for decline.
	 *
	 * @var string
	 */
	public const DECLINE_ACTION = 'pph_decline_request';

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestResolution $requests Resolves the request row.
	 * @param Cancel            $cancel   Owns the order-side effect of an approval.
	 * @param Logger            $logger   Logs what a stale or unreadable request could not resolve.
	 */
	public function __construct(
		private RequestResolution $requests,
		private Cancel $cancel,
		private Logger $logger
	) {}

	/**
	 * Wires the admin-post hooks.
	 *
	 * @since 0.9.0
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_post_' . self::APPROVE_ACTION,
			function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside approve() before anything else runs.
				$this->approve( $_POST );
			}
		);

		add_action(
			'admin_post_' . self::DECLINE_ACTION,
			function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside decline() before anything else runs.
				$this->decline( $_POST );
			}
		);
	}

	/**
	 * Approves a request.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return void
	 */
	public function approve( array $params ): void {
		$this->authorise( $params );

		$request = $this->find( $params );

		if ( null !== $request && $request->is_open() ) {
			$this->resolve_approval( $request, self::current_user_id(), self::admin_note( $params ) );
		}

		$this->redirect( $params );
	}

	/**
	 * Declines a request. Never touches the order it was raised against.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return void
	 */
	public function decline( array $params ): void {
		$this->authorise( $params );

		$request = $this->find( $params );

		if ( null !== $request && $request->is_open() ) {
			$order = wc_get_order( $request->order_id );

			$this->requests->decline(
				$request,
				$order instanceof \WC_Order ? $order : null,
				self::current_user_id(),
				self::admin_note( $params )
			);
		}

		$this->redirect( $params );
	}

	/**
	 * Carries out an approval: transitions the order via Cancel::approve(),
	 * unless it turns out to already be cancelled by another route.
	 *
	 * The request row is resolved *before* the order transitions. See
	 * Cancel::approve()'s docblock for why the order matters.
	 *
	 * @since 0.9.0
	 *
	 * @param Request     $request    Request to approve.
	 * @param int         $user_id    Approving staff member.
	 * @param string|null $admin_note Internal note, if one was given.
	 * @return void
	 */
	private function resolve_approval( Request $request, int $user_id, ?string $admin_note ): void {
		$order = wc_get_order( $request->order_id );

		if ( ! $order instanceof \WC_Order ) {
			$this->logger->warning(
				'Cannot approve a cancellation request: its order no longer resolves.',
				array(
					'request_id' => $request->id,
					'order_id'   => $request->order_id,
				)
			);

			return;
		}

		if ( Request::TYPE_CANCELLATION !== $request->type ) {
			// No executable action exists for this request type yet; leave it
			// pending for a human to handle by other means rather than
			// silently mis-resolving it.
			return;
		}

		if ( $order->has_status( 'cancelled' ) ) {
			$this->requests->complete( $request, $order, $user_id, RequestService::reconciliation_note() );

			return;
		}

		$this->requests->approve( $request, $order, $user_id, $admin_note );
		$this->cancel->approve( $order, $user_id );
	}

	/**
	 * Capability, then nonce. In that order, always.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return void
	 */
	private function authorise( array $params ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage cancellation requests.', 'post-purchase-hub' ),
				'',
				array( 'response' => 403 )
			);
		}

		$nonce = isset( $params['_wpnonce'] ) && is_scalar( $params['_wpnonce'] ) ? (string) $params['_wpnonce'] : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die(
				esc_html__( 'This link has expired. Please go back and try again.', 'post-purchase-hub' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Finds the request a submission names, defensively.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return Request|null
	 */
	private function find( array $params ): ?Request {
		$id = isset( $params['request_id'] ) && is_scalar( $params['request_id'] ) ? absint( $params['request_id'] ) : 0;

		return $id > 0 ? $this->requests->find( $id ) : null;
	}

	/**
	 * The sanitised internal note, if the form included one.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return string|null
	 */
	private static function admin_note( array $params ): ?string {
		if ( ! isset( $params['admin_note'] ) || ! is_scalar( $params['admin_note'] ) ) {
			return null;
		}

		$note = sanitize_text_field( wp_unslash( (string) $params['admin_note'] ) );

		return '' === $note ? null : $note;
	}

	/**
	 * The logged-in user id, 0 for none.
	 *
	 * @since 0.9.0
	 * @return int
	 */
	private static function current_user_id(): int {
		return get_current_user_id();
	}

	/**
	 * Sends the merchant back where they came from.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $params Posted form data.
	 * @return void
	 */
	private function redirect( array $params ): void {
		$referer = isset( $params['_wp_http_referer'] ) && is_scalar( $params['_wp_http_referer'] )
			? (string) $params['_wp_http_referer']
			: admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE );

		wp_safe_redirect( $referer );
	}
}
