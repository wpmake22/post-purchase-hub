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
<section class="pph-order-notes" data-pph-order-notes>
	<h2 class="pph-order-notes__heading"><?php esc_html_e( 'Updates from the store', 'wpmake-post-purchase-hub' ); ?></h2>

	<ol class="pph-order-notes__list">
		<?php foreach ( $notes as $pph_note ) : ?>
			<li class="pph-order-notes__note" data-pph-order-note>
				<?php if ( '' !== $pph_note['datetime'] ) : ?>
					<time class="pph-order-notes__time" datetime="<?php echo esc_attr( $pph_note['datetime'] ); ?>">
						<?php echo esc_html( $pph_note['date_label'] ); ?>
					</time>
				<?php endif; ?>

				<div class="pph-order-notes__body">
					<?php echo wp_kses_post( wpautop( wptexturize( $pph_note['content'] ) ) ); ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
