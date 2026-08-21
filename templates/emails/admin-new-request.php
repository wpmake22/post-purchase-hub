<?php
/**
 * New request admin email (HTML).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/admin-new-request.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var \WC_Order|null              $order              Order the request was raised against, if it still resolves.
 * @var \PostPurchaseHub\Requests\Request $request       The request itself.
 * @var string|null                  $reason_label       Human label for the request's reason code, prepared by Emails\NewRequestAdmin.
 * @var string                       $queue_url          Deep link into the admin request queue.
 * @var string                       $email_heading      Configured or default heading.
 * @var string                       $additional_content Merchant-configured footer copy.
 * @var bool                         $sent_to_admin      Always true; this email is admin-only.
 * @var bool                         $plain_text         Always false in this template.
 * @var \WC_Email                    $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %s: order number. */
		esc_html__( 'A customer has raised a request against order #%s.', 'post-purchase-hub' ),
		esc_html( $order instanceof WC_Order ? $order->get_order_number() : (string) $request->order_id )
	);
	?>
</p>

<?php if ( null !== $reason_label ) : ?>
<p>
	<strong><?php esc_html_e( 'Reason:', 'post-purchase-hub' ); ?></strong>
	<?php echo esc_html( $reason_label ); ?>
</p>
<?php endif; ?>

<?php if ( null !== $request->customer_note ) : ?>
<p><strong><?php esc_html_e( 'Customer note:', 'post-purchase-hub' ); ?></strong></p>
<blockquote>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() runs first; wpautop() only wraps the already-escaped text in <p> tags.
	echo wpautop( esc_html( $request->customer_note ) );
	?>
</blockquote>
<?php endif; ?>

<p>
	<a href="<?php echo esc_url( $queue_url ); ?>"><?php esc_html_e( 'Review this request', 'post-purchase-hub' ); ?></a>
</p>

<?php
if ( $order instanceof WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
