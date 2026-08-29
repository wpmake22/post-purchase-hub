<?php
/**
 * Reorder reconciliation summary.
 *
 * Shown before anything reaches the cart: every line of the past order with
 * the outcome it will have, and — only when something can actually be added —
 * the confirmation that submits to `wpmphub/v1/reorder`.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/reorder-summary.php.
 *
 * @package PostPurchaseHub
 * @version 0.12.0
 *
 * @var array{
 *     order_id: int,
 *     lines: array<int, array{outcome: string, name: string, quantity: int, requested: int, status: string, price_note: string, url: string}>,
 *     cart_items: int,
 *     can_confirm: bool,
 *     default_mode: string,
 *     merge_label: string,
 *     replace_label: string,
 *     confirm_label: string,
 *     unavailable: string,
 *     capped_notice: string
 * } $reorder Prepared by Frontend\ReorderView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $reorder['lines'] ) ) {
	return;
}
?>
<section
	class="wpmphub-reorder"
	data-wpmphub-reorder
	data-wpmphub-order-id="<?php echo esc_attr( (string) $reorder['order_id'] ); ?>"
	aria-label="<?php esc_attr_e( 'Buy these again', 'wpmake-post-purchase-hub' ); ?>"
>
	<h3 class="wpmphub-reorder__heading">
		<?php esc_html_e( 'Before we add these to your cart', 'wpmake-post-purchase-hub' ); ?>
	</h3>

	<p class="wpmphub-reorder__intro" data-wpmphub-reorder-intro>
		<?php esc_html_e( 'Nothing has been added yet. Here is what has changed since you ordered.', 'wpmake-post-purchase-hub' ); ?>
	</p>

	<ul class="wpmphub-reorder__lines" data-wpmphub-reorder-lines>
		<?php foreach ( $reorder['lines'] as $wpmphub_line ) : ?>
			<li
				class="wpmphub-reorder__line wpmphub-reorder__line--<?php echo esc_attr( $wpmphub_line['outcome'] ); ?>"
				data-wpmphub-reorder-line
				data-wpmphub-reorder-outcome="<?php echo esc_attr( $wpmphub_line['outcome'] ); ?>"
			>
				<span class="wpmphub-reorder__name" data-wpmphub-reorder-name>
					<?php echo esc_html( $wpmphub_line['name'] ); ?>
				</span>

				<span class="wpmphub-reorder__quantity" data-wpmphub-reorder-quantity>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: quantity on the original order. */
							__( 'Ordered: %d', 'wpmake-post-purchase-hub' ),
							$wpmphub_line['requested']
						)
					);
					?>
				</span>

				<span class="wpmphub-reorder__status" data-wpmphub-reorder-status>
					<?php echo esc_html( $wpmphub_line['status'] ); ?>
				</span>

				<?php if ( '' !== $wpmphub_line['price_note'] ) : ?>
					<span class="wpmphub-reorder__price" data-wpmphub-reorder-price>
						<?php echo esc_html( $wpmphub_line['price_note'] ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $wpmphub_line['url'] ) : ?>
					<a class="wpmphub-reorder__link" href="<?php echo esc_url( $wpmphub_line['url'] ); ?>" data-wpmphub-reorder-link>
						<?php esc_html_e( 'Choose options', 'wpmake-post-purchase-hub' ); ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( '' !== $reorder['capped_notice'] ) : ?>
		<p class="wpmphub-reorder__notice" data-wpmphub-reorder-capped>
			<?php echo esc_html( $reorder['capped_notice'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $reorder['unavailable'] ) : ?>
		<p class="wpmphub-reorder__notice wpmphub-reorder__notice--empty" data-wpmphub-reorder-unavailable role="status">
			<?php echo esc_html( $reorder['unavailable'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $reorder['can_confirm'] ) : ?>
		<form class="wpmphub-reorder__form" data-wpmphub-reorder-form novalidate>
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $reorder['order_id'] ); ?>" data-wpmphub-reorder-order-id />

			<?php if ( $reorder['cart_items'] > 0 ) : ?>
				<fieldset class="wpmphub-reorder__modes">
					<legend class="wpmphub-reorder__legend">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of items already in the cart. */
								_n(
									'You already have %d item in your cart.',
									'You already have %d items in your cart.',
									$reorder['cart_items'],
									'wpmake-post-purchase-hub'
								),
								$reorder['cart_items']
							)
						);
						?>
					</legend>

					<label class="wpmphub-reorder__mode">
						<input
							type="radio"
							name="mode"
							value="merge"
							data-wpmphub-reorder-mode
							<?php checked( 'merge', $reorder['default_mode'] ); ?>
						/>
						<?php echo esc_html( $reorder['merge_label'] ); ?>
					</label>

					<label class="wpmphub-reorder__mode">
						<input
							type="radio"
							name="mode"
							value="replace"
							data-wpmphub-reorder-mode
							<?php checked( 'replace', $reorder['default_mode'] ); ?>
						/>
						<?php echo esc_html( $reorder['replace_label'] ); ?>
					</label>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="mode" value="<?php echo esc_attr( $reorder['default_mode'] ); ?>" data-wpmphub-reorder-mode-default />
			<?php endif; ?>

			<p class="wpmphub-reorder__error" data-wpmphub-reorder-error hidden aria-live="assertive"></p>

			<button type="submit" class="button button-primary" data-wpmphub-reorder-confirm>
				<?php echo esc_html( $reorder['confirm_label'] ); ?>
			</button>
		</form>
	<?php endif; ?>
</section>
