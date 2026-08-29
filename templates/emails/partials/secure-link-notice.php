<?php
/**
 * Secure order link notice injected into an opted-in WooCommerce email.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/emails/partials/secure-link-notice.php.
 *
 * @package PostPurchaseHub
 * @version 0.10.0
 *
 * @var string $link_url   The signed link.
 * @var bool   $plain_text Whether the surrounding email is the plain-text part.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( $plain_text ) {
	echo "\n" . esc_html__( 'You can also view this order without signing in:', 'wpmake-post-purchase-hub' ) . ' ' . esc_url( $link_url ) . "\n\n";

	return;
}

$wpmphub_notice = sprintf(
	/* translators: 1: opening <a> tag linking to the secure order link, 2: closing </a> tag. */
	__( 'You can also %1$sview this order without signing in%2$s.', 'wpmake-post-purchase-hub' ),
	'<a href="' . esc_url( $link_url ) . '">',
	'</a>'
);
?>
<p>
	<?php echo wp_kses( $wpmphub_notice, array( 'a' => array( 'href' => array() ) ) ); ?>
</p>
<?php
