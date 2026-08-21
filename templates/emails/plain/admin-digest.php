<?php
/**
 * Daily admin digest email (plain text).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/plain/admin-digest.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var int       $pending_count      Requests currently pending.
 * @var int       $new_count          Requests created since the previous digest.
 * @var string    $queue_url          Link to the admin request queue.
 * @var string    $email_heading      Configured or default heading.
 * @var string    $additional_content Merchant-configured footer copy.
 * @var bool      $sent_to_admin      Always true; this email is admin-only.
 * @var bool      $plain_text         Always true in this template.
 * @var \WC_Email $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo sprintf(
	/* translators: %d: number of requests created since the previous digest. */
	esc_html( _n( '%d new request since your last digest.', '%d new requests since your last digest.', $new_count, 'post-purchase-hub' ) ),
	(int) $new_count
) . "\n\n";

echo sprintf(
	/* translators: %d: number of currently pending requests. */
	esc_html( _n( '%d request is currently pending.', '%d requests are currently pending.', $pending_count, 'post-purchase-hub' ) ),
	(int) $pending_count
) . "\n\n";

echo esc_html__( 'Open the request queue:', 'post-purchase-hub' ) . ' ' . esc_url( $queue_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() is the escaping call itself, matching WooCommerce's own plain-text footer.
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
