<?php
/**
 * Reorder reconciliation summary.
 *
 * Shown before anything reaches the cart: every line of the past order with
 * the outcome it will have, and — only when something can actually be added —
 * the confirmation that submits to `pph/v1/reorder`.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/reorder-summary.php.
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
	class="pph-reorder"
	data-pph-reorder
	data-pph-order-id="<?php echo esc_attr( (string) $reorder['order_id'] ); ?>"
	aria-label="<?php esc_attr_e( 'Buy these again', 'post-purchase-hub' ); ?>"
>
	<h3 class="pph-reorder__heading">
		<?php esc_html_e( 'Before we add these to your cart', 'post-purchase-hub' ); ?>
	</h3>

	<p class="pph-reorder__intro" data-pph-reorder-intro>
		<?php esc_html_e( 'Nothing has been added yet. Here is what has changed since you ordered.', 'post-purchase-hub' ); ?>
	</p>

	<ul class="pph-reorder__lines" data-pph-reorder-lines>
		<?php foreach ( $reorder['lines'] as $pph_line ) : ?>
			<li
				class="pph-reorder__line pph-reorder__line--<?php echo esc_attr( $pph_line['outcome'] ); ?>"
				data-pph-reorder-line
				data-pph-reorder-outcome="<?php echo esc_attr( $pph_line['outcome'] ); ?>"
			>
				<span class="pph-reorder__name" data-pph-reorder-name>
					<?php echo esc_html( $pph_line['name'] ); ?>
				</span>

				<span class="pph-reorder__quantity" data-pph-reorder-quantity>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: quantity on the original order. */
							__( 'Ordered: %d', 'post-purchase-hub' ),
							$pph_line['requested']
						)
					);
					?>
				</span>

				<span class="pph-reorder__status" data-pph-reorder-status>
					<?php echo esc_html( $pph_line['status'] ); ?>
				</span>

				<?php if ( '' !== $pph_line['price_note'] ) : ?>
					<span class="pph-reorder__price" data-pph-reorder-price>
						<?php echo esc_html( $pph_line['price_note'] ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $pph_line['url'] ) : ?>
					<a class="pph-reorder__link" href="<?php echo esc_url( $pph_line['url'] ); ?>" data-pph-reorder-link>
						<?php esc_html_e( 'Choose options', 'post-purchase-hub' ); ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( '' !== $reorder['capped_notice'] ) : ?>
		<p class="pph-reorder__notice" data-pph-reorder-capped>
			<?php echo esc_html( $reorder['capped_notice'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $reorder['unavailable'] ) : ?>
		<p class="pph-reorder__notice pph-reorder__notice--empty" data-pph-reorder-unavailable role="status">
			<?php echo esc_html( $reorder['unavailable'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $reorder['can_confirm'] ) : ?>
		<form class="pph-reorder__form" data-pph-reorder-form novalidate>
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $reorder['order_id'] ); ?>" data-pph-reorder-order-id />

			<?php if ( $reorder['cart_items'] > 0 ) : ?>
				<fieldset class="pph-reorder__modes">
					<legend class="pph-reorder__legend">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of items already in the cart. */
								_n(
									'You already have %d item in your cart.',
									'You already have %d items in your cart.',
									$reorder['cart_items'],
									'post-purchase-hub'
								),
								$reorder['cart_items']
							)
						);
						?>
					</legend>

					<label class="pph-reorder__mode">
						<input
							type="radio"
							name="mode"
							value="merge"
							data-pph-reorder-mode
							<?php checked( 'merge', $reorder['default_mode'] ); ?>
						/>
						<?php echo esc_html( $reorder['merge_label'] ); ?>
					</label>

					<label class="pph-reorder__mode">
						<input
							type="radio"
							name="mode"
							value="replace"
							data-pph-reorder-mode
							<?php checked( 'replace', $reorder['default_mode'] ); ?>
						/>
						<?php echo esc_html( $reorder['replace_label'] ); ?>
					</label>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="mode" value="<?php echo esc_attr( $reorder['default_mode'] ); ?>" data-pph-reorder-mode-default />
			<?php endif; ?>

			<p class="pph-reorder__error" data-pph-reorder-error hidden aria-live="assertive"></p>

			<button type="submit" class="button button-primary" data-pph-reorder-confirm>
				<?php echo esc_html( $reorder['confirm_label'] ); ?>
			</button>
		</form>
	<?php endif; ?>
</section>
