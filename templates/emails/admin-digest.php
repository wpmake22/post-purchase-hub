<?php
/**
 * Daily admin digest email (HTML).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/admin-digest.php.
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
 * @var bool      $plain_text         Always false in this template.
 * @var \WC_Email $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %d: number of requests created since the previous digest. */
		esc_html( _n( '%d new request since your last digest.', '%d new requests since your last digest.', $new_count, 'post-purchase-hub' ) ),
		(int) $new_count
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: %d: number of currently pending requests. */
		esc_html( _n( '%d request is currently pending.', '%d requests are currently pending.', $pending_count, 'post-purchase-hub' ) ),
		(int) $pending_count
	);
	?>
</p>

<p>
	<a href="<?php echo esc_url( $queue_url ); ?>"><?php esc_html_e( 'Open the request queue', 'post-purchase-hub' ); ?></a>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
