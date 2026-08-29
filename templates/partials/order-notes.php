<?php
/**
 * Merchant notes to the customer.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/order-notes.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.1
 *
 * @var array<int, array{content: string, datetime: string, date_label: string}> $notes Prepared by Frontend\Renderer.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $notes ) ) {
	return;
}
?>
<section class="wpmphub-order-notes" data-wpmphub-order-notes>
	<h2 class="wpmphub-order-notes__heading"><?php esc_html_e( 'Updates from the store', 'wpmake-post-purchase-hub' ); ?></h2>

	<ol class="wpmphub-order-notes__list">
		<?php foreach ( $notes as $wpmphub_note ) : ?>
			<li class="wpmphub-order-notes__note" data-wpmphub-order-note>
				<?php if ( '' !== $wpmphub_note['datetime'] ) : ?>
					<time class="wpmphub-order-notes__time" datetime="<?php echo esc_attr( $wpmphub_note['datetime'] ); ?>">
						<?php echo esc_html( $wpmphub_note['date_label'] ); ?>
					</time>
				<?php endif; ?>

				<div class="wpmphub-order-notes__body">
					<?php echo wp_kses_post( wpautop( wptexturize( $wpmphub_note['content'] ) ) ); ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
