<?php
/**
 * Estimated delivery range.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/eta.php.
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
<p class="pph-eta" data-pph-eta>
	<span class="pph-eta__label" data-pph-eta-label>
		<?php esc_html_e( 'Estimated delivery:', 'post-purchase-hub' ); ?>
	</span>

	<time
		class="pph-eta__range"
		datetime="<?php echo esc_attr( $eta['start'] ); ?>"
		data-pph-eta-start="<?php echo esc_attr( $eta['start'] ); ?>"
		data-pph-eta-end="<?php echo esc_attr( $eta['end'] ); ?>"
	>
		<?php echo esc_html( $eta['label'] ); ?>
	</time>
</p>
