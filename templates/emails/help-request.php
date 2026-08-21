<?php
/**
 * Customer help request admin email (HTML).
 *
 * Override by copying this file to yourtheme/post-purchase-hub/emails/help-request.php.
 *
 * @package PostPurchaseHub
 * @version 0.13.0
 *
 * @var \WC_Order|null                              $order              Order the question is about.
 * @var \PostPurchaseHub\Actions\HelpContext|null   $help               Submission and the order context it carries, prepared by Actions\Help.
 * @var string                                      $email_heading      Configured or default heading.
 * @var string                                      $additional_content Merchant-configured footer copy.
 * @var bool                                        $sent_to_admin      Always true; this email is admin-only.
 * @var bool                                        $plain_text         Always false in this template.
 * @var \WC_Email                                   $email              The email instance rendering this template.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! $help instanceof PostPurchaseHub\Actions\HelpContext ) {
	return;
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: 1: customer name, 2: order number. */
		esc_html__( '%1$s has asked for help with order #%2$s.', 'post-purchase-hub' ),
		esc_html( $help->customer_name ),
		esc_html( $help->order_number )
	);
	?>
</p>

<p>
	<strong><?php esc_html_e( 'About:', 'post-purchase-hub' ); ?></strong>
	<?php echo esc_html( $help->topic_label ); ?>
</p>

<blockquote>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() runs first; wpautop() only wraps the already-escaped text in <p> tags.
	echo wpautop( esc_html( $help->message ) );
	?>
</blockquote>

<h2><?php esc_html_e( 'Order context', 'post-purchase-hub' ); ?></h2>

<ul>
	<li>
		<strong><?php esc_html_e( 'Status:', 'post-purchase-hub' ); ?></strong>
		<?php echo esc_html( $help->status_label ); ?>
	</li>
	<?php if ( '' !== $help->timeline_state ) : ?>
	<li>
		<strong><?php esc_html_e( 'Timeline:', 'post-purchase-hub' ); ?></strong>
		<?php echo esc_html( $help->timeline_state ); ?>
	</li>
	<?php endif; ?>
	<?php if ( '' !== $help->placed_on ) : ?>
	<li>
		<strong><?php esc_html_e( 'Placed:', 'post-purchase-hub' ); ?></strong>
		<?php echo esc_html( $help->placed_on ); ?>
	</li>
	<?php endif; ?>
	<li>
		<strong><?php esc_html_e( 'Reply to:', 'post-purchase-hub' ); ?></strong>
		<?php echo esc_html( $help->customer_email ); ?>
	</li>
</ul>

<?php if ( array() !== $help->items ) : ?>
<ul>
	<?php foreach ( $help->items as $pph_item ) : ?>
		<li><?php echo esc_html( $pph_item ); ?></li>
	<?php endforeach; ?>
	<?php if ( $help->items_omitted > 0 ) : ?>
		<li>
			<?php
			printf(
				/* translators: %d: number of further items on the order, not listed. */
				esc_html( _n( 'and %d more item', 'and %d more items', $help->items_omitted, 'post-purchase-hub' ) ),
				absint( $help->items_omitted )
			);
			?>
		</li>
	<?php endif; ?>
</ul>
<?php endif; ?>

<?php if ( '' !== $help->admin_url ) : ?>
<p>
	<a href="<?php echo esc_url( $help->admin_url ); ?>"><?php esc_html_e( 'Open this order', 'post-purchase-hub' ); ?></a>
</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
