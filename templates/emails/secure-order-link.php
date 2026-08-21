<?php
/**
 * Secure order link email (HTML).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/secure-order-link.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order $order              Order the link points to.
 * @var string    $link_url           The signed link itself.
 * @var string    $email_heading      Configured or default heading.
 * @var string    $additional_content Merchant-configured footer copy.
 * @var bool      $sent_to_admin      Always false; this email is customer-only.
 * @var bool      $plain_text         Always false in this template.
 * @var \WC_Email $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	if ( '' !== $order->get_billing_first_name() ) {
		/* translators: %s: customer first name. */
		printf( esc_html__( 'Hi %s,', 'post-purchase-hub' ), esc_html( $order->get_billing_first_name() ) );
	} else {
		esc_html_e( 'Hi,', 'post-purchase-hub' );
	}
	?>
</p>

<p>
	<?php esc_html_e( 'Use the link below to view this order without signing in.', 'post-purchase-hub' ); ?>
</p>

<p>
	<a href="<?php echo esc_url( $link_url ); ?>"><?php esc_html_e( 'View my order', 'post-purchase-hub' ); ?></a>
</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
