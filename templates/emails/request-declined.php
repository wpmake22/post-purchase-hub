<?php
/**
 * Request declined email (HTML).
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/emails/request-declined.php.
 *
 * Deliberately never prints `$request->admin_note` — that field is internal
 * merchant context, never customer-facing copy (see `Emails\RequestDeclined`'s
 * docblock).
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order|null              $order              Order the request was raised against.
 * @var \PostPurchaseHub\Requests\Request $request       The request itself.
 * @var string                       $email_heading      Configured or default heading.
 * @var string                       $additional_content Merchant-configured footer copy.
 * @var bool                         $sent_to_admin      Always false; this email is customer-only.
 * @var bool                         $plain_text         Always false in this template.
 * @var \WC_Email                    $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	if ( $order instanceof WC_Order && '' !== $order->get_billing_first_name() ) {
		/* translators: %s: customer first name. */
		printf( esc_html__( 'Hi %s,', 'wpmake-post-purchase-hub' ), esc_html( $order->get_billing_first_name() ) );
	} else {
		esc_html_e( 'Hi,', 'wpmake-post-purchase-hub' );
	}
	?>
</p>

<p>
	<?php esc_html_e( "We looked into your request and, unfortunately, we're not able to action it. Your order is unchanged.", 'wpmake-post-purchase-hub' ); ?>
</p>

<?php
if ( $order instanceof WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
