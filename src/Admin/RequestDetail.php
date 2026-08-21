<?php
/**
 * Single-request detail view for the admin queue.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;

/**
 * Renders one request's items, reason, customer note, full request history
 * against the same order, and the approve/decline forms.
 *
 * Unlike `templates/` this is not a theme-overridable customer surface — no
 * theme reaches into wp-admin — so it renders directly rather than through
 * `Frontend\TemplateLoader`, matching how `WP_List_Table` itself works.
 * Every value is escaped at the point of output regardless.
 *
 * @since 0.9.0
 */
final class RequestDetail {

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param RequestRepository $requests Reads the request and its order's history.
	 */
	public function __construct( private RequestRepository $requests ) {}

	/**
	 * Renders one request, or a "not found" notice.
	 *
	 * @since 0.9.0
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	public function render( int $request_id ): void {
		$request = $this->requests->find( $request_id );

		echo '<div class="wrap pph-request-detail">';

		if ( null === $request ) {
			printf(
				'<h1>%s</h1><p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'Request not found', 'post-purchase-hub' ),
				esc_html__( 'This request no longer exists.', 'post-purchase-hub' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE ) ),
				esc_html__( '&larr; Back to requests', 'post-purchase-hub' )
			);
			echo '</div>';

			return;
		}

		$order = wc_get_order( $request->order_id );
		$order = $order instanceof \WC_Order ? $order : null;

		$this->render_heading( $request, $order );
		$this->render_items( $order );
		$this->render_customer_input( $request );

		if ( $request->is_open() ) {
			$this->render_actions( $request );
		}

		$this->render_history( $request );

		echo '</div>';
	}

	/**
	 * Renders the back-link, order reference and current status.
	 *
	 * @since 0.9.0
	 *
	 * @param Request        $request Request being viewed.
	 * @param \WC_Order|null $order   Its order, if it still resolves.
	 * @return void
	 */
	private function render_heading( Request $request, ?\WC_Order $order ): void {
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE ) ),
			esc_html__( '&larr; Back to requests', 'post-purchase-hub' )
		);

		printf(
			/* translators: %d: order number. */
			'<h1>' . esc_html__( 'Cancellation request for order #%d', 'post-purchase-hub' ) . '</h1>',
			(int) $request->order_id
		);

		if ( $order instanceof \WC_Order ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $order->get_edit_order_url() ),
				esc_html__( 'View order', 'post-purchase-hub' )
			);
		}

		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Status:', 'post-purchase-hub' ), esc_html( $request->status ) );
	}

	/**
	 * Renders the order's line items, read-only.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order|null $order Order, if it still resolves.
	 * @return void
	 */
	private function render_items( ?\WC_Order $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Items', 'post-purchase-hub' ) . '</h2><ul>';

		foreach ( $order->get_items() as $item ) {
			printf( '<li>%s &times; %s</li>', esc_html( (string) $item->get_quantity() ), esc_html( $item->get_name() ) );
		}

		echo '</ul>';
	}

	/**
	 * Renders the customer's reason and note.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $request Request being viewed.
	 * @return void
	 */
	private function render_customer_input( Request $request ): void {
		echo '<h2>' . esc_html__( 'Customer', 'post-purchase-hub' ) . '</h2>';
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Reason:', 'post-purchase-hub' ), esc_html( (string) $request->reason_code ) );

		if ( null !== $request->customer_note && '' !== $request->customer_note ) {
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Note:', 'post-purchase-hub' ), esc_html( $request->customer_note ) );
		}
	}

	/**
	 * Renders the approve/decline forms.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $request Request being viewed.
	 * @return void
	 */
	private function render_actions( Request $request ): void {
		echo '<h2>' . esc_html__( 'Decision', 'post-purchase-hub' ) . '</h2>';

		printf(
			'<textarea name="admin_note" form="pph-approve-%1$d" placeholder="%2$s"></textarea>',
			(int) $request->id,
			esc_attr__( 'Internal note (not shown to the customer)', 'post-purchase-hub' )
		);

		$this->render_form( $request, RequestActionController::APPROVE_ACTION, __( 'Approve', 'post-purchase-hub' ), 'pph-approve-' . $request->id );
		$this->render_form( $request, RequestActionController::DECLINE_ACTION, __( 'Decline', 'post-purchase-hub' ), 'pph-decline-' . $request->id );
	}

	/**
	 * Renders one admin-post.php form.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $request     Request being acted on.
	 * @param string  $action      admin-post.php action name.
	 * @param string  $label       Submit button label.
	 * @param string  $form_id     Element id, referenced by the note textarea's `form` attribute.
	 * @return void
	 */
	private function render_form( Request $request, string $action, string $label, string $form_id ): void {
		echo '<form method="post" id="' . esc_attr( $form_id ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="request_id" value="%d">', (int) $request->id );
		wp_nonce_field( RequestActionController::NONCE_ACTION );
		printf( '<button type="submit" class="button">%s</button>', esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * Renders every request raised against the same order, newest first.
	 *
	 * @since 0.9.0
	 *
	 * @param Request $request Request being viewed.
	 * @return void
	 */
	private function render_history( Request $request ): void {
		$history = $this->requests->find_by_order( $request->order_id );

		if ( count( $history ) < 2 ) {
			return;
		}

		echo '<h2>' . esc_html__( 'History for this order', 'post-purchase-hub' ) . '</h2><ul>';

		foreach ( $history as $past ) {
			printf(
				'<li>%s &mdash; %s (%s)</li>',
				esc_html( $past->created_at ),
				esc_html( $past->status ),
				esc_html( $past->type )
			);
		}

		echo '</ul>';
	}
}
