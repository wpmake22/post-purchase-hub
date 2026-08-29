<?php
/**
 * Eligible self-service actions for one order.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/actions.php.
 *
 * @package PostPurchaseHub
 * @version 0.7.0
 *
 * @var array<int, array{id: string, label: string, url: string, description: string}> $actions Prepared by Frontend\ActionsRenderer.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $actions ) ) {
	return;
}
?>
<section class="wpmphub-actions" data-wpmphub-actions aria-label="<?php esc_attr_e( 'Available actions', 'wpmake-post-purchase-hub' ); ?>">
	<ul class="wpmphub-actions__list" data-wpmphub-actions-list>
		<?php foreach ( $actions as $wpmphub_action ) : ?>
			<li class="wpmphub-actions__item" data-wpmphub-action="<?php echo esc_attr( $wpmphub_action['id'] ); ?>">
				<a
					class="wpmphub-actions__link button"
					href="<?php echo esc_url( $wpmphub_action['url'] ); ?>"
					data-wpmphub-action-link
				>
					<?php echo esc_html( $wpmphub_action['label'] ); ?>
				</a>

				<?php if ( '' !== $wpmphub_action['description'] ) : ?>
					<p class="wpmphub-actions__description" data-wpmphub-action-description>
						<?php echo esc_html( $wpmphub_action['description'] ); ?>
					</p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
