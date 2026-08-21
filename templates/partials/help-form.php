<?php
/**
 * Contextual help form for one order.
 *
 * Rendered inside a <details> disclosure so the form is reachable, and its
 * context readable, with no JavaScript at all — only submitting it needs any.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/help-form.php.
 *
 * @package PostPurchaseHub
 * @version 0.13.0
 *
 * @var array{
 *     element_id: string,
 *     order_id: int,
 *     heading: string,
 *     intro: string,
 *     context_heading: string,
 *     summary: array<int, array{label: string, value: string}>,
 *     items: array<int, string>,
 *     items_note: string,
 *     topic_label: string,
 *     topics: array<string, string>,
 *     message_label: string,
 *     message_hint: string,
 *     message_max_length: int,
 *     submit_label: string
 * } $help Prepared by Frontend\HelpView.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $help['topics'] ) ) {
	return;
}
?>
<details
	class="pph-help"
	id="<?php echo esc_attr( $help['element_id'] ); ?>"
	data-pph-help
	data-pph-order-id="<?php echo esc_attr( (string) $help['order_id'] ); ?>"
>
	<summary class="pph-help__toggle" data-pph-help-toggle>
		<?php echo esc_html( $help['heading'] ); ?>
	</summary>

	<div class="pph-help__panel">
		<p class="pph-help__intro"><?php echo esc_html( $help['intro'] ); ?></p>

		<div class="pph-help__context" data-pph-help-context>
			<h3 class="pph-help__context-heading"><?php echo esc_html( $help['context_heading'] ); ?></h3>

			<ul class="pph-help__context-list">
				<?php foreach ( $help['summary'] as $pph_row ) : ?>
					<li class="pph-help__context-item">
						<span class="pph-help__context-label"><?php echo esc_html( $pph_row['label'] ); ?></span>
						<span class="pph-help__context-value"><?php echo esc_html( $pph_row['value'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( array() !== $help['items'] ) : ?>
				<ul class="pph-help__items">
					<?php foreach ( $help['items'] as $pph_item ) : ?>
						<li class="pph-help__item"><?php echo esc_html( $pph_item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( '' !== $help['items_note'] ) : ?>
				<p class="pph-help__items-note"><?php echo esc_html( $help['items_note'] ); ?></p>
			<?php endif; ?>
		</div>

		<form class="pph-help__form" data-pph-help-form novalidate>
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $help['order_id'] ); ?>" data-pph-help-order-id />

			<div class="pph-help__field">
				<label for="<?php echo esc_attr( $help['element_id'] . '-topic' ); ?>">
					<?php echo esc_html( $help['topic_label'] ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $help['element_id'] . '-topic' ); ?>"
					name="topic"
					data-pph-help-topic
					required
				>
					<?php foreach ( $help['topics'] as $pph_code => $pph_label ) : ?>
						<option value="<?php echo esc_attr( $pph_code ); ?>"><?php echo esc_html( $pph_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pph-help__field">
				<label for="<?php echo esc_attr( $help['element_id'] . '-message' ); ?>">
					<?php echo esc_html( $help['message_label'] ); ?>
				</label>
				<textarea
					id="<?php echo esc_attr( $help['element_id'] . '-message' ); ?>"
					name="message"
					data-pph-help-message
					rows="4"
					maxlength="<?php echo esc_attr( (string) $help['message_max_length'] ); ?>"
					aria-describedby="<?php echo esc_attr( $help['element_id'] . '-hint' ); ?>"
					required
				></textarea>
				<p class="pph-help__hint" id="<?php echo esc_attr( $help['element_id'] . '-hint' ); ?>">
					<?php echo esc_html( $help['message_hint'] ); ?>
				</p>
			</div>

			<p class="pph-help__error" data-pph-help-error hidden aria-live="assertive"></p>

			<div class="pph-help__actions">
				<button type="submit" class="button button-primary" data-pph-help-submit>
					<?php echo esc_html( $help['submit_label'] ); ?>
				</button>
			</div>
		</form>
	</div>
</details>
