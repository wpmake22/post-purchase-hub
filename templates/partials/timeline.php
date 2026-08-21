<?php
/**
 * Order timeline.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/timeline.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var array{order_id: int, status: string, historical: bool, notice: string, stages: array<int, array<string, string>>, branch: array<string, string>|null, current_label: string} $timeline Prepared by Frontend\TimelineView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $timeline['stages'] ) ) {
	return;
}

$pph_heading_id = 'pph-timeline-heading-' . (int) $timeline['order_id'];
?>
<section
	class="pph-timeline"
	data-pph-timeline
	data-pph-order-id="<?php echo esc_attr( (string) $timeline['order_id'] ); ?>"
	data-pph-order-status="<?php echo esc_attr( $timeline['status'] ); ?>"
	aria-labelledby="<?php echo esc_attr( $pph_heading_id ); ?>"
>
	<h2 class="pph-timeline__heading" id="<?php echo esc_attr( $pph_heading_id ); ?>">
		<?php esc_html_e( 'Order progress', 'post-purchase-hub' ); ?>
	</h2>

	<ol class="pph-timeline__stages" data-pph-timeline-stages>
		<?php foreach ( $timeline['stages'] as $pph_stage ) : ?>
			<li
				class="pph-timeline__stage pph-timeline__stage--<?php echo esc_attr( $pph_stage['state'] ); ?>"
				data-pph-stage="<?php echo esc_attr( $pph_stage['key'] ); ?>"
				data-pph-stage-state="<?php echo esc_attr( $pph_stage['state'] ); ?>"
			>
				<span class="pph-timeline__marker" aria-hidden="true"></span>

				<span class="pph-timeline__label" data-pph-stage-label>
					<?php echo esc_html( $pph_stage['label'] ); ?>
				</span>

				<span class="pph-timeline__state" data-pph-stage-state-label>
					<?php echo esc_html( $pph_stage['state_label'] ); ?>
				</span>

				<?php if ( '' !== $pph_stage['datetime'] ) : ?>
					<time
						class="pph-timeline__time"
						datetime="<?php echo esc_attr( $pph_stage['datetime'] ); ?>"
						data-pph-stage-time
					>
						<?php echo esc_html( $pph_stage['date_label'] ); ?>
					</time>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php if ( null !== $timeline['branch'] ) : ?>
		<p
			class="pph-timeline__branch pph-timeline__branch--<?php echo esc_attr( $timeline['branch']['key'] ); ?>"
			data-pph-branch="<?php echo esc_attr( $timeline['branch']['key'] ); ?>"
		>
			<strong class="pph-timeline__branch-label"><?php echo esc_html( $timeline['branch']['label'] ); ?></strong>

			<?php if ( '' !== $timeline['branch']['datetime'] ) : ?>
				<time
					class="pph-timeline__time"
					datetime="<?php echo esc_attr( $timeline['branch']['datetime'] ); ?>"
					data-pph-branch-time
				>
					<?php echo esc_html( $timeline['branch']['date_label'] ); ?>
				</time>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $timeline['notice'] ) : ?>
		<p class="pph-timeline__notice" data-pph-timeline-notice>
			<?php echo esc_html( $timeline['notice'] ); ?>
		</p>
	<?php endif; ?>
</section>
