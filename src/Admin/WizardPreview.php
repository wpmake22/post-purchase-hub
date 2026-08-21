<?php
/**
 * The wizard's display-mode preview.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Frontend\Renderer;

/**
 * Shows a merchant their own timeline before they turn it on.
 *
 * Built from a real order — the most recent one on the store — and rendered
 * through the same `TimelineView` and the same partial the storefront uses, so
 * the preview cannot flatter the result. A store with no orders yet gets a
 * sentence saying so rather than a mock-up of data it does not have.
 *
 * What it deliberately does not do is render two whole order pages side by
 * side. Full replacement changes the page *around* this section — the order
 * table, the addresses, the theme's own markup — and drawing that inside
 * wp-admin would mean running frontend templates out of context, which is how a
 * preview ends up lying about the page it is previewing. The section is real;
 * the difference between the two modes is stated in words next to it.
 *
 * @since 0.14.0
 */
final class WizardPreview {

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param Renderer $renderer The storefront's own renderer, called exactly as the order page calls it.
	 */
	public function __construct( private Renderer $renderer ) {}

	/**
	 * Renders the preview, or an honest note when there is nothing to preview.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function render(): void {
		$order = $this->sample_order();

		echo '<div class="pph-wizard__preview" data-pph-wizard-preview>';

		if ( ! $order instanceof \WC_Order ) {
			printf(
				'<p class="description" data-pph-wizard-preview-empty>%s</p>',
				esc_html__( 'Once you have an order, this is where you will see exactly what your customers see. There are no orders on this store yet.', 'post-purchase-hub' )
			);

			echo '</div>';

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: order number the preview is built from. */
					__( 'Built from order #%s, exactly as a customer would see it.', 'post-purchase-hub' ),
					$order->get_order_number()
				)
			)
		);

		// The storefront's own renderer, deliberately: a preview that draws its
		// own markup is a preview that can be wrong.
		$this->renderer->render_timeline( $order );

		echo '</div>';
	}

	/**
	 * The order the preview is built from.
	 *
	 * @since 0.14.0
	 *
	 * @return \WC_Order|null
	 */
	private function sample_order(): ?\WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'type'    => 'shop_order',
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);

		if ( ! is_array( $orders ) || array() === $orders ) {
			return null;
		}

		$order = reset( $orders );

		return $order instanceof \WC_Order ? $order : null;
	}
}
