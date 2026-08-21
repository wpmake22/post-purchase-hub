<?php
/**
 * Cancellation-request modal.
 *
 * One instance per page, reused for whichever order's "Request cancellation"
 * link was clicked — assets/src/js/requests.js sets the hidden order id field
 * before showing it. Rendered in the footer so it never disturbs the tab
 * order of the page around it while hidden.
 *
 * Override by copying this file to yourtheme/post-purchase-hub/partials/request-modal.php.
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
	class="pph-modal"
	data-pph-request-modal
	role="dialog"
	aria-modal="true"
	aria-labelledby="pph-request-modal-heading"
	aria-describedby="pph-request-modal-description"
	hidden
>
	<div class="pph-modal__backdrop" data-pph-modal-backdrop></div>

	<div class="pph-modal__panel">
		<button
			type="button"
			class="pph-modal__close"
			data-pph-modal-close
			aria-label="<?php esc_attr_e( 'Close', 'post-purchase-hub' ); ?>"
		>&times;</button>

		<h2 id="pph-request-modal-heading" class="pph-modal__heading">
			<?php esc_html_e( 'Request cancellation', 'post-purchase-hub' ); ?>
		</h2>

		<p id="pph-request-modal-description" class="pph-modal__description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: expected response time in hours. */
					_n(
						'This sends a request to the store — it does not cancel your order right away. We usually respond within %d hour.',
						'This sends a request to the store — it does not cancel your order right away. We usually respond within %d hours.',
						$modal['expected_response_hours'],
						'post-purchase-hub'
					),
					$modal['expected_response_hours']
				)
			);
			?>
		</p>

		<form class="pph-modal__form" data-pph-request-form novalidate>
			<input type="hidden" name="order_id" data-pph-request-order-id value="" />

			<div class="pph-modal__field">
				<label for="pph-request-reason"><?php esc_html_e( 'Reason', 'post-purchase-hub' ); ?></label>
				<select
					id="pph-request-reason"
					name="reason_code"
					data-pph-request-reason
					aria-describedby="pph-request-reason-error"
					required
				>
					<?php foreach ( $modal['reason_codes'] as $pph_code => $pph_label ) : ?>
						<option value="<?php echo esc_attr( $pph_code ); ?>"><?php echo esc_html( $pph_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p id="pph-request-reason-error" class="pph-modal__error" data-pph-request-reason-error hidden></p>
			</div>

			<div class="pph-modal__field">
				<label for="pph-request-note">
					<?php esc_html_e( 'Anything else? (optional)', 'post-purchase-hub' ); ?>
				</label>
				<textarea id="pph-request-note" name="note" data-pph-request-note rows="3"></textarea>
			</div>

			<p class="pph-modal__error" data-pph-request-form-error hidden aria-live="assertive"></p>

			<div class="pph-modal__actions">
				<button type="button" class="button" data-pph-modal-close>
					<?php esc_html_e( 'Cancel', 'post-purchase-hub' ); ?>
				</button>
				<button type="submit" class="button button-primary" data-pph-request-submit>
					<span data-pph-request-submit-label><?php esc_html_e( 'Send request', 'post-purchase-hub' ); ?></span>
				</button>
			</div>
		</form>
	</div>
</div>
