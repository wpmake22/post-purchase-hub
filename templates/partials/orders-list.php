<?php
/**
 * Orders with their timelines.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/orders-list.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var array<int, array{number: string, url: string, timeline: array<string, mixed>}> $orders     Prepared by Frontend\Renderer.
 * @var string                                                                         $empty_text Message shown when there are no orders.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $orders ) ) : ?>
	<p class="pph-orders__empty" data-pph-orders-empty><?php echo esc_html( $empty_text ); ?></p>
	<?php
	return;
endif;
?>
<div class="pph-orders" data-pph-orders>
	<?php foreach ( $orders as $pph_order ) : ?>
		<article class="pph-orders__order" data-pph-order-id="<?php echo esc_attr( (string) $pph_order['timeline']['order_id'] ); ?>">
			<h2 class="pph-orders__number">
				<a href="<?php echo esc_url( $pph_order['url'] ); ?>">
					<?php
					printf(
						/* translators: %s: order number, without a leading hash. */
						esc_html__( 'Order %s', 'wpmake-post-purchase-hub' ),
						esc_html( $pph_order['number'] )
					);
					?>
				</a>
			</h2>

			<?php
			/**
			 * Renders one order's timeline inside the list.
			 *
			 * @since 0.4.0
			 *
			 * @param array<string, mixed> $timeline Prepared timeline view model.
			 */
			do_action( 'pph_render_timeline_partial', $pph_order['timeline'] );
			?>
		</article>
	<?php endforeach; ?>
</div>
