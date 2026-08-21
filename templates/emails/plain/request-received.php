<?php
/**
 * Request received email (plain text).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/plain/request-received.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order|null              $order              Order the request was raised against.
 * @var \PostPurchaseHub\Requests\Request $request       The request itself.
 * @var string                       $response_time_text Expected merchant response time, as customer-facing copy.
 * @var string                       $email_heading      Configured or default heading.
 * @var string                       $additional_content Merchant-configured footer copy.
 * @var bool                         $sent_to_admin      Always false; this email is customer-only.
 * @var bool                         $plain_text         Always true in this template.
 * @var \WC_Email                    $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( $order instanceof WC_Order && '' !== $order->get_billing_first_name() ) {
	/* translators: %s: customer first name. */
	echo sprintf( esc_html__( 'Hi %s,', 'post-purchase-hub' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi,', 'post-purchase-hub' ) . "\n\n";
}

echo esc_html__( "We received your request and a member of our team will get back to you. Here's what to expect:", 'post-purchase-hub' ) . "\n\n";

/* translators: %s: expected response time, e.g. "24 hours". */
echo sprintf( esc_html__( 'Expected response time: %s.', 'post-purchase-hub' ), esc_html( $response_time_text ) ) . "\n\n";

if ( null !== $request->customer_note ) {
	echo esc_html__( 'What you told us:', 'post-purchase-hub' ) . "\n\n";
	echo "----------\n\n";
	echo esc_html( $request->customer_note ) . "\n\n";
	echo "----------\n\n";
}

if ( $order instanceof WC_Order ) {
	/*
	 * @hooked WC_Emails::order_details() Shows the order details table.
	 */
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	echo "\n----------------------------------------\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() is the escaping call itself, matching WooCommerce's own plain-text footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
