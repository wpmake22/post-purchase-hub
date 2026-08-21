<?php
/**
 * New request admin email (plain text).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/plain/admin-new-request.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order|null              $order              Order the request was raised against, if it still resolves.
 * @var \PostPurchaseHub\Requests\Request $request       The request itself.
 * @var string|null                  $reason_label       Human label for the request's reason code.
 * @var string                       $queue_url          Deep link into the admin request queue.
 * @var string                       $email_heading      Configured or default heading.
 * @var string                       $additional_content Merchant-configured footer copy.
 * @var bool                         $sent_to_admin      Always true; this email is admin-only.
 * @var bool                         $plain_text         Always true in this template.
 * @var \WC_Email                    $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo sprintf(
	/* translators: %s: order number. */
	esc_html__( 'A customer has raised a request against order #%s.', 'post-purchase-hub' ),
	esc_html( $order instanceof WC_Order ? $order->get_order_number() : (string) $request->order_id )
) . "\n\n";

if ( null !== $reason_label ) {
	echo esc_html__( 'Reason:', 'post-purchase-hub' ) . ' ' . esc_html( $reason_label ) . "\n\n";
}

if ( null !== $request->customer_note ) {
	echo esc_html__( 'Customer note:', 'post-purchase-hub' ) . "\n\n";
	echo "----------\n\n";
	echo esc_html( $request->customer_note ) . "\n\n";
	echo "----------\n\n";
}

echo esc_html__( 'Review this request:', 'post-purchase-hub' ) . ' ' . esc_url( $queue_url ) . "\n\n";

if ( $order instanceof WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	echo "\n----------------------------------------\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() is the escaping call itself, matching WooCommerce's own plain-text footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
