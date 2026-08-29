<?php
/**
 * Order timeline.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/timeline.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var array{order_id: int, status: string, historical: bool, notice: string, stages: array<int, array<string, string>>, branch: array<string, string>|null, branch_note: string, current_label: string} $timeline Prepared by Frontend\TimelineView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $timeline['stages'] ) ) {
	return;
}

$wpmphub_heading_id = 'wpmphub-timeline-heading-' . (int) $timeline['order_id'];
?>
<section
	class="wpmphub-timeline"
	data-wpmphub-timeline
	data-wpmphub-order-id="<?php echo esc_attr( (string) $timeline['order_id'] ); ?>"
	data-wpmphub-order-status="<?php echo esc_attr( $timeline['status'] ); ?>"
	aria-labelledby="<?php echo esc_attr( $wpmphub_heading_id ); ?>"
>
	<h2 class="wpmphub-timeline__heading" id="<?php echo esc_attr( $wpmphub_heading_id ); ?>">
		<?php esc_html_e( 'Order progress', 'wpmake-post-purchase-hub' ); ?>
	</h2>

	<ol class="wpmphub-timeline__stages" data-wpmphub-timeline-stages>
		<?php foreach ( $timeline['stages'] as $wpmphub_stage ) : ?>
			<li
				class="wpmphub-timeline__stage wpmphub-timeline__stage--<?php echo esc_attr( $wpmphub_stage['state'] ); ?>"
				data-wpmphub-stage="<?php echo esc_attr( $wpmphub_stage['key'] ); ?>"
				data-wpmphub-stage-state="<?php echo esc_attr( $wpmphub_stage['state'] ); ?>"
			>
				<span class="wpmphub-timeline__marker" aria-hidden="true"></span>

				<span class="wpmphub-timeline__label" data-wpmphub-stage-label>
					<?php echo esc_html( $wpmphub_stage['label'] ); ?>
				</span>

				<span class="wpmphub-timeline__state" data-wpmphub-stage-state-label>
					<?php echo esc_html( $wpmphub_stage['state_label'] ); ?>
				</span>

				<?php if ( '' !== $wpmphub_stage['datetime'] ) : ?>
					<time
						class="wpmphub-timeline__time"
						datetime="<?php echo esc_attr( $wpmphub_stage['datetime'] ); ?>"
						data-wpmphub-stage-time
					>
						<?php echo esc_html( $wpmphub_stage['date_label'] ); ?>
					</time>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php if ( null !== $timeline['branch'] ) : ?>
		<p
			class="wpmphub-timeline__branch wpmphub-timeline__branch--<?php echo esc_attr( $timeline['branch']['key'] ); ?>"
			data-wpmphub-branch="<?php echo esc_attr( $timeline['branch']['key'] ); ?>"
		>
			<strong class="wpmphub-timeline__branch-label" data-wpmphub-branch-label>
				<?php echo esc_html( $timeline['branch']['label'] ); ?>
			</strong>

			<?php if ( '' !== $timeline['branch']['datetime'] ) : ?>
				<time
					class="wpmphub-timeline__time"
					datetime="<?php echo esc_attr( $timeline['branch']['datetime'] ); ?>"
					data-wpmphub-branch-time
				>
					<?php echo esc_html( $timeline['branch']['date_label'] ); ?>
				</time>
			<?php endif; ?>

			<?php if ( '' !== $timeline['branch_note'] ) : ?>
				<span class="wpmphub-timeline__branch-note" data-wpmphub-branch-note>
					<?php echo esc_html( $timeline['branch_note'] ); ?>
				</span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $timeline['notice'] ) : ?>
		<p class="wpmphub-timeline__notice" data-wpmphub-timeline-notice>
			<?php echo esc_html( $timeline['notice'] ); ?>
		</p>
	<?php endif; ?>
</section>
