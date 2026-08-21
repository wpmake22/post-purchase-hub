<?php
/**
 * Eligible self-service actions for one order.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/actions.php.
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
<section class="pph-actions" data-pph-actions aria-label="<?php esc_attr_e( 'Available actions', 'post-purchase-hub' ); ?>">
	<ul class="pph-actions__list" data-pph-actions-list>
		<?php foreach ( $actions as $pph_action ) : ?>
			<li class="pph-actions__item" data-pph-action="<?php echo esc_attr( $pph_action['id'] ); ?>">
				<a
					class="pph-actions__link button"
					href="<?php echo esc_url( $pph_action['url'] ); ?>"
					data-pph-action-link
				>
					<?php echo esc_html( $pph_action['label'] ); ?>
				</a>

				<?php if ( '' !== $pph_action['description'] ) : ?>
					<p class="pph-actions__description" data-pph-action-description>
						<?php echo esc_html( $pph_action['description'] ); ?>
					</p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
