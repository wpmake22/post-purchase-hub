<?php
/**
 * Request received email (HTML).
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/emails/request-received.php.
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
 * @var bool                         $plain_text         Always false in this template.
 * @var \WC_Email                    $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
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
	<?php esc_html_e( "We received your request and a member of our team will get back to you. Here's what to expect:", 'wpmake-post-purchase-hub' ); ?>
</p>

<p>
	<?php
	printf(
		/* translators: %s: expected response time, e.g. "24 hours". */
		esc_html__( 'Expected response time: %s.', 'wpmake-post-purchase-hub' ),
		esc_html( $response_time_text )
	);
	?>
</p>

<?php if ( null !== $request->customer_note ) : ?>
<p><?php esc_html_e( 'What you told us:', 'wpmake-post-purchase-hub' ); ?></p>
<blockquote>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() runs first; wpautop() only wraps the already-escaped text in <p> tags.
	echo wpautop( esc_html( $request->customer_note ) );
	?>
</blockquote>
<?php endif; ?>

<?php
if ( $order instanceof WC_Order ) {
	/*
	 * @hooked WC_Emails::order_details() Shows the order details table.
	 */
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
