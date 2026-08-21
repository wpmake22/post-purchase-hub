<?php
/**
 * Customer help request admin email (plain text).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/plain/help-request.php.
 *
 * @package PostPurchaseHub
 * @version 0.13.0
 *
 * @var \WC_Order|null                            $order              Order the question is about.
 * @var \PostPurchaseHub\Actions\HelpContext|null $help               Submission and the order context it carries.
 * @var string                                    $email_heading      Configured or default heading.
 * @var string                                    $additional_content Merchant-configured footer copy.
 * @var bool                                      $sent_to_admin      Always true; this email is admin-only.
 * @var bool                                      $plain_text         Always true in this template.
 * @var \WC_Email                                 $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! $help instanceof PostPurchaseHub\Actions\HelpContext ) {
	return;
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo sprintf(
	/* translators: 1: customer name, 2: order number. */
	esc_html__( '%1$s has asked for help with order #%2$s.', 'post-purchase-hub' ),
	esc_html( $help->customer_name ),
	esc_html( $help->order_number )
) . "\n\n";

echo esc_html__( 'About:', 'post-purchase-hub' ) . ' ' . esc_html( $help->topic_label ) . "\n\n";

echo "----------\n\n";
echo esc_html( $help->message ) . "\n\n";
echo "----------\n\n";

echo esc_html__( 'Order context', 'post-purchase-hub' ) . "\n\n";
echo esc_html__( 'Status:', 'post-purchase-hub' ) . ' ' . esc_html( $help->status_label ) . "\n";

if ( '' !== $help->timeline_state ) {
	echo esc_html__( 'Timeline:', 'post-purchase-hub' ) . ' ' . esc_html( $help->timeline_state ) . "\n";
}

if ( '' !== $help->placed_on ) {
	echo esc_html__( 'Placed:', 'post-purchase-hub' ) . ' ' . esc_html( $help->placed_on ) . "\n";
}

echo esc_html__( 'Reply to:', 'post-purchase-hub' ) . ' ' . esc_html( $help->customer_email ) . "\n\n";

foreach ( $help->items as $pph_item ) {
	echo '- ' . esc_html( $pph_item ) . "\n";
}

if ( $help->items_omitted > 0 ) {
	echo '- ' . sprintf(
		/* translators: %d: number of further items on the order, not listed. */
		esc_html( _n( 'and %d more item', 'and %d more items', $help->items_omitted, 'post-purchase-hub' ) ),
		absint( $help->items_omitted )
	) . "\n";
}

if ( array() !== $help->items ) {
	echo "\n";
}

if ( '' !== $help->admin_url ) {
	echo esc_html__( 'Open this order:', 'post-purchase-hub' ) . ' ' . esc_url( $help->admin_url ) . "\n\n";
}

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() is the escaping call itself, matching WooCommerce's own plain-text footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
