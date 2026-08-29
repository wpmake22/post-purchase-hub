<?php
/**
 * Compact timeline state for the orders list.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/timeline-summary.php.
 *
 * Deliberately one line. This renders inside a table cell that every theme
 * styles differently, so it adds a phrase rather than a layout.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var array{order_id: int, status: string, historical: bool, notice: string, stages: array<int, array<string, string>>, branch: array<string, string>|null, current: array<string, string>|null} $timeline Prepared by Frontend\TimelineView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( null === $timeline['current'] ) {
	return;
}
?>
<span
	class="wpmphub-timeline-summary"
	data-wpmphub-timeline-summary
	data-wpmphub-order-id="<?php echo esc_attr( (string) $timeline['order_id'] ); ?>"
	data-wpmphub-stage="<?php echo esc_attr( $timeline['current']['key'] ); ?>"
>
	<span class="wpmphub-timeline-summary__label" data-wpmphub-summary-label>
		<?php echo esc_html( $timeline['current']['label'] ); ?>
	</span>

	<?php if ( '' !== $timeline['current']['datetime'] ) : ?>
		<time
			class="wpmphub-timeline-summary__time"
			datetime="<?php echo esc_attr( $timeline['current']['datetime'] ); ?>"
			data-wpmphub-summary-time
		>
			<?php echo esc_html( $timeline['current']['date_label'] ); ?>
		</time>
	<?php endif; ?>
</span>
