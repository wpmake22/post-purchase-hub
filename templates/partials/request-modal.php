<?php
/**
 * Cancellation-request modal.
 *
 * One instance per page, reused for whichever order's "Request cancellation"
 * link was clicked — assets/src/js/requests.js sets the hidden order id field
 * before showing it. Rendered in the footer so it never disturbs the tab
 * order of the page around it while hidden.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/partials/request-modal.php.
 *
 * @package PostPurchaseHub
 * @version 0.8.0
 *
 * @var array{reason_codes: array<string, string>, expected_response_hours: int} $modal Prepared by Frontend\RequestModalRenderer.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( empty( $modal['reason_codes'] ) ) {
	return;
}
?>
<div
	class="wpmphub-modal"
	data-wpmphub-request-modal
	role="dialog"
	aria-modal="true"
	aria-labelledby="wpmphub-request-modal-heading"
	aria-describedby="wpmphub-request-modal-description"
	hidden
>
	<div class="wpmphub-modal__backdrop" data-wpmphub-modal-backdrop></div>

	<div class="wpmphub-modal__panel">
		<button
			type="button"
			class="wpmphub-modal__close"
			data-wpmphub-modal-close
			aria-label="<?php esc_attr_e( 'Close', 'wpmake-post-purchase-hub' ); ?>"
		>&times;</button>

		<h2 id="wpmphub-request-modal-heading" class="wpmphub-modal__heading">
			<?php esc_html_e( 'Request cancellation', 'wpmake-post-purchase-hub' ); ?>
		</h2>

		<p id="wpmphub-request-modal-description" class="wpmphub-modal__description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: expected response time in hours. */
					_n(
						'This sends a request to the store — it does not cancel your order right away. We usually respond within %d hour.',
						'This sends a request to the store — it does not cancel your order right away. We usually respond within %d hours.',
						$modal['expected_response_hours'],
						'wpmake-post-purchase-hub'
					),
					$modal['expected_response_hours']
				)
			);
			?>
		</p>

		<form class="wpmphub-modal__form" data-wpmphub-request-form novalidate>
			<input type="hidden" name="order_id" data-wpmphub-request-order-id value="" />

			<div class="wpmphub-modal__field">
				<label for="wpmphub-request-reason"><?php esc_html_e( 'Reason', 'wpmake-post-purchase-hub' ); ?></label>
				<select
					id="wpmphub-request-reason"
					name="reason_code"
					data-wpmphub-request-reason
					aria-describedby="wpmphub-request-reason-error"
					required
				>
					<?php foreach ( $modal['reason_codes'] as $wpmphub_code => $wpmphub_label ) : ?>
						<option value="<?php echo esc_attr( $wpmphub_code ); ?>"><?php echo esc_html( $wpmphub_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p id="wpmphub-request-reason-error" class="wpmphub-modal__error" data-wpmphub-request-reason-error hidden></p>
			</div>

			<div class="wpmphub-modal__field">
				<label for="wpmphub-request-note">
					<?php esc_html_e( 'Anything else? (optional)', 'wpmake-post-purchase-hub' ); ?>
				</label>
				<textarea id="wpmphub-request-note" name="note" data-wpmphub-request-note rows="3"></textarea>
			</div>

			<p class="wpmphub-modal__error" data-wpmphub-request-form-error hidden aria-live="assertive"></p>

			<div class="wpmphub-modal__actions">
				<button type="button" class="button" data-wpmphub-modal-close>
					<?php esc_html_e( 'Cancel', 'wpmake-post-purchase-hub' ); ?>
				</button>
				<button type="submit" class="button button-primary" data-wpmphub-request-submit>
					<span data-wpmphub-request-submit-label><?php esc_html_e( 'Send request', 'wpmake-post-purchase-hub' ); ?></span>
				</button>
			</div>
		</form>
	</div>
</div>
