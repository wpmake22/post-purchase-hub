<?php
/**
 * Contextual help form for one order.
 *
 * Rendered inside a <details> disclosure so the form is reachable, and its
 * context readable, with no JavaScript at all — only submitting it needs any.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/help-form.php.
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
	class="wpmphub-help"
	id="<?php echo esc_attr( $help['element_id'] ); ?>"
	data-wpmphub-help
	data-wpmphub-order-id="<?php echo esc_attr( (string) $help['order_id'] ); ?>"
>
	<summary class="wpmphub-help__toggle" data-wpmphub-help-toggle>
		<?php echo esc_html( $help['heading'] ); ?>
	</summary>

	<div class="wpmphub-help__panel">
		<p class="wpmphub-help__intro"><?php echo esc_html( $help['intro'] ); ?></p>

		<div class="wpmphub-help__context" data-wpmphub-help-context>
			<h3 class="wpmphub-help__context-heading"><?php echo esc_html( $help['context_heading'] ); ?></h3>

			<ul class="wpmphub-help__context-list">
				<?php foreach ( $help['summary'] as $wpmphub_row ) : ?>
					<li class="wpmphub-help__context-item">
						<span class="wpmphub-help__context-label"><?php echo esc_html( $wpmphub_row['label'] ); ?></span>
						<span class="wpmphub-help__context-value"><?php echo esc_html( $wpmphub_row['value'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( array() !== $help['items'] ) : ?>
				<ul class="wpmphub-help__items">
					<?php foreach ( $help['items'] as $wpmphub_item ) : ?>
						<li class="wpmphub-help__item"><?php echo esc_html( $wpmphub_item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( '' !== $help['items_note'] ) : ?>
				<p class="wpmphub-help__items-note"><?php echo esc_html( $help['items_note'] ); ?></p>
			<?php endif; ?>
		</div>

		<form class="wpmphub-help__form" data-wpmphub-help-form novalidate>
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $help['order_id'] ); ?>" data-wpmphub-help-order-id />

			<div class="wpmphub-help__field">
				<label for="<?php echo esc_attr( $help['element_id'] . '-topic' ); ?>">
					<?php echo esc_html( $help['topic_label'] ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $help['element_id'] . '-topic' ); ?>"
					name="topic"
					data-wpmphub-help-topic
					required
				>
					<?php foreach ( $help['topics'] as $wpmphub_code => $wpmphub_label ) : ?>
						<option value="<?php echo esc_attr( $wpmphub_code ); ?>"><?php echo esc_html( $wpmphub_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="wpmphub-help__field">
				<label for="<?php echo esc_attr( $help['element_id'] . '-message' ); ?>">
					<?php echo esc_html( $help['message_label'] ); ?>
				</label>
				<textarea
					id="<?php echo esc_attr( $help['element_id'] . '-message' ); ?>"
					name="message"
					data-wpmphub-help-message
					rows="4"
					maxlength="<?php echo esc_attr( (string) $help['message_max_length'] ); ?>"
					aria-describedby="<?php echo esc_attr( $help['element_id'] . '-hint' ); ?>"
					required
				></textarea>
				<p class="wpmphub-help__hint" id="<?php echo esc_attr( $help['element_id'] . '-hint' ); ?>">
					<?php echo esc_html( $help['message_hint'] ); ?>
				</p>
			</div>

			<p class="wpmphub-help__error" data-wpmphub-help-error hidden aria-live="assertive"></p>

			<div class="wpmphub-help__actions">
				<button type="submit" class="button button-primary" data-wpmphub-help-submit>
					<?php echo esc_html( $help['submit_label'] ); ?>
				</button>
			</div>
		</form>
	</div>
</details>
