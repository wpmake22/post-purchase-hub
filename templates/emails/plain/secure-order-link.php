<?php
/**
 * Secure order link email (plain text).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/plain/secure-order-link.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order $order              Order the link points to.
 * @var string    $link_url           The signed link itself.
 * @var string    $email_heading      Configured or default heading.
 * @var string    $additional_content Merchant-configured footer copy.
 * @var bool      $sent_to_admin      Always false; this email is customer-only.
 * @var bool      $plain_text         Always true in this template.
 * @var \WC_Email $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $order->get_billing_first_name() ) {
	/* translators: %s: customer first name. */
	echo sprintf( esc_html__( 'Hi %s,', 'post-purchase-hub' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi,', 'post-purchase-hub' ) . "\n\n";
}

echo esc_html__( 'Use the link below to view this order without signing in.', 'post-purchase-hub' ) . "\n\n";
echo esc_url( $link_url ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() is the escaping call itself, matching WooCommerce's own plain-text footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
