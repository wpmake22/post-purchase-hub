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
	<p class="wpmphub-orders__empty" data-wpmphub-orders-empty><?php echo esc_html( $empty_text ); ?></p>
	<?php
	return;
endif;
?>
<div class="wpmphub-orders" data-wpmphub-orders>
	<?php foreach ( $orders as $wpmphub_order ) : ?>
		<article class="wpmphub-orders__order" data-wpmphub-order-id="<?php echo esc_attr( (string) $wpmphub_order['timeline']['order_id'] ); ?>">
			<h2 class="wpmphub-orders__number">
				<a href="<?php echo esc_url( $wpmphub_order['url'] ); ?>">
					<?php
					printf(
						/* translators: %s: order number, without a leading hash. */
						esc_html__( 'Order %s', 'wpmake-post-purchase-hub' ),
						esc_html( $wpmphub_order['number'] )
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
			do_action( 'wpmphub_render_timeline_partial', $wpmphub_order['timeline'] );
			?>
		</article>
	<?php endforeach; ?>
</div>
