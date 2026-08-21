<?php
/**
 * The help form on the customer's order page.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContext;
use PostPurchaseHub\Actions\HelpTopics;

/**
 * Draws the form the "Get help with this order" action points at.
 *
 * Hooked to `woocommerce_view_order` after the actions list (25), so the form
 * sits below the button that links to it. That one hook covers every surface
 * this plugin renders an order on: WooCommerce's own detail template fires it,
 * this plugin's replacement template re-fires it, and so does the guest order
 * template a signed link lands on — which is what lets a guest ask for help
 * without a second code path deciding whether they may.
 *
 * The eligibility question is not answered here. `Actions\Help::check()`
 * answers it for the button, for this form and for the REST route alike, so a
 * form on the page is never evidence that a submission will be accepted — the
 * route asks again.
 *
 * @since 0.13.0
 */
final class HelpView {

	/**
	 * Priority at which the form renders.
	 *
	 * After ActionsRenderer's own hook (25): the form is the answer to a link
	 * in that list, so it reads as belonging beneath it.
	 *
	 * @var int
	 */
	private const PRIORITY = 28;

	/**
	 * Order ids whose form has already been drawn this request.
	 *
	 * Mirrors Renderer::$rendered: the replacement template re-fires
	 * `woocommerce_view_order`, and two forms for one order would mean two
	 * elements sharing one id.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param Help           $help      The action this form belongs to.
	 * @param TemplateLoader $templates Template loader.
	 */
	public function __construct( private Help $help, private TemplateLoader $templates ) {}

	/**
	 * Wires the rendering hook.
	 *
	 * @since 0.13.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_view_order', array( $this, 'render' ), self::PRIORITY );
	}

	/**
	 * Renders the form for an order that may be asked about.
	 *
	 * @since 0.13.0
	 *
	 * @param mixed $order_id Order id, as passed by woocommerce_view_order.
	 * @return void
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$id = $order->get_id();

		if ( isset( $this->rendered[ $id ] ) ) {
			return;
		}

		$this->rendered[ $id ] = true;

		if ( ! $this->help->check( $order )->eligible ) {
			return;
		}

		$this->templates->render(
			'partials/help-form.php',
			array( 'help' => $this->view_model( $order, $this->help->context_for( $order ) ) )
		);
	}

	/**
	 * Builds everything the template prints, already formatted.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order   $order   Order the form is about.
	 * @param HelpContext $context Order context the submission will carry.
	 * @return array<string, mixed>
	 */
	private function view_model( \WC_Order $order, HelpContext $context ): array {
		return array(
			'element_id'         => Help::element_id( $order ),
			'order_id'           => $context->order_id,
			'heading'            => Help::label(),
			'intro'              => __( 'Send the store a message about this order. What we already know about it is attached, so you do not have to explain it again.', 'post-purchase-hub' ),
			'context_heading'    => __( 'Attached to your message', 'post-purchase-hub' ),
			'summary'            => $this->summary_rows( $context ),
			'items'              => $context->items,
			'items_note'         => $this->items_note( $context ),
			'topic_label'        => __( 'What is this about?', 'post-purchase-hub' ),
			'topics'             => HelpTopics::labels(),
			'message_label'      => __( 'Your message', 'post-purchase-hub' ),
			'message_hint'       => sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Up to %d characters.', 'post-purchase-hub' ),
				Help::MESSAGE_MAX_LENGTH
			),
			'message_max_length' => Help::MESSAGE_MAX_LENGTH,
			'submit_label'       => __( 'Send message', 'post-purchase-hub' ),
		);
	}

	/**
	 * The context rows, with the ones this order has nothing to say about left out.
	 *
	 * @since 0.13.0
	 *
	 * @param HelpContext $context Context to present.
	 * @return array<int, array{label: string, value: string}>
	 */
	private function summary_rows( HelpContext $context ): array {
		$candidates = array(
			array( __( 'Order', 'post-purchase-hub' ), '#' . $context->order_number ),
			array( __( 'Status', 'post-purchase-hub' ), $context->status_label ),
			array( __( 'Progress', 'post-purchase-hub' ), $context->timeline_state ),
			array( __( 'Placed', 'post-purchase-hub' ), $context->placed_on ),
		);

		$rows = array();

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate[1] ) {
				continue;
			}

			$rows[] = array(
				'label' => $candidate[0],
				'value' => $candidate[1],
			);
		}

		return $rows;
	}

	/**
	 * The note standing in for the items past the summary cap.
	 *
	 * @since 0.13.0
	 *
	 * @param HelpContext $context Context to present.
	 * @return string
	 */
	private function items_note( HelpContext $context ): string {
		if ( $context->items_omitted < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of further items on the order, not listed. */
			_n(
				'and %d more item on this order',
				'and %d more items on this order',
				$context->items_omitted,
				'post-purchase-hub'
			),
			$context->items_omitted
		);
	}
}
