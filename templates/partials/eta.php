<?php
/**
 * Estimated delivery range.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/eta.php.
 *
 * @package PostPurchaseHub
 * @version 0.5.0
 *
 * @var array{visible: bool, start: string, end: string, label: string} $eta Prepared by Frontend\EstimatedDeliveryView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $eta['visible'] ) ) {
	return;
}
?>
<p class="wpmphub-eta" data-wpmphub-eta>
	<span class="wpmphub-eta__label" data-wpmphub-eta-label>
		<?php esc_html_e( 'Estimated delivery:', 'wpmake-post-purchase-hub' ); ?>
	</span>

	<time
		class="wpmphub-eta__range"
		datetime="<?php echo esc_attr( $eta['start'] ); ?>"
		data-wpmphub-eta-start="<?php echo esc_attr( $eta['start'] ); ?>"
		data-wpmphub-eta-end="<?php echo esc_attr( $eta['end'] ); ?>"
	>
		<?php echo esc_html( $eta['label'] ); ?>
	</time>
</p>
