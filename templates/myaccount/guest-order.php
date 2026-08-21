<?php
/**
 * Order detail for a guest arriving from a signed link.
 *
 * Shown in place of WooCommerce's My Account login form once
 * Security\OwnershipResolver has confirmed the visitor may see this order. It
 * carries a heading of its own because a guest arrives here from an email and
 * needs to know which order they are looking at — the account pages that
 * surround a logged-in customer are not here to tell them.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/myaccount/guest-order.php.
 *
 * @package PostPurchaseHub
 * @version 0.11.0
 *
 * @var \WC_Order $order        Order being viewed, prepared by Frontend\GuestOrderView.
 * @var int       $order_id     Order id.
 * @var string    $order_number Customer-facing order number.
 * @var string    $status_label Translated status name.
 * @var string    $placed_on    Formatted order date.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="pph-guest-order" data-pph-guest-order="<?php echo esc_attr( (string) $order_id ); ?>">

	<p class="pph-guest-order__summary" data-pph-guest-order-summary>
		<?php
		printf(
			/* translators: 1: order number, 2: order date, 3: order status */
			esc_html__( 'Order %1$s was placed on %2$s and is currently %3$s.', 'post-purchase-hub' ),
			'<mark class="order-number">' . esc_html( $order_number ) . '</mark>',
			'<mark class="order-date">' . esc_html( $placed_on ) . '</mark>',
			'<mark class="order-status">' . esc_html( $status_label ) . '</mark>'
		);
		?>
	</p>

	<?php
	/**
	 * Renders this order's timeline.
	 *
	 * @since 0.11.0
	 *
	 * @param \WC_Order $order Order to describe.
	 */
	do_action( 'pph_render_order_detail', $order );

	/**
	 * Renders the merchant's notes to this customer.
	 *
	 * WooCommerce's own view-order template lists these as "Order updates".
	 * This template stands in for that one, so it has to carry them.
	 *
	 * @since 0.11.0
	 *
	 * @param \WC_Order $order Order whose notes to show.
	 */
	do_action( 'pph_render_order_notes', $order );

	/**
	 * Fires after this plugin's guest order heading.
	 *
	 * Re-fires WooCommerce's own hook, exactly as this plugin's replacement
	 * template does, so core's order details table and this plugin's eligible
	 * actions both render — and so does anything an integration hooked there.
	 *
	 * @since 0.11.0
	 *
	 * @param int $order_id Order id.
	 */
	do_action( 'woocommerce_view_order', (int) $order_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-fires WooCommerce's own hook: this file stands in for the core template that would have fired it.
	?>
</div>
