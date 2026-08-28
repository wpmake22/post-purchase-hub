<?php
/**
 * Guest order-lookup form.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/lookup/form.php.
 *
 * @package PostPurchaseHub
 * @version 0.11.0
 *
 * @var array{type: string, message: string}|null $notice Outcome of a previous submission, prepared by Frontend\LookupForm.
 * @var string                                    $action Where the form posts.
 * @var array{submit: string, number: string, email: string} $fields Field names, prepared by Frontend\LookupForm.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="pph-lookup" data-pph-lookup>

	<?php if ( is_array( $notice ) ) : ?>
		<p
			class="pph-lookup__notice pph-lookup__notice--<?php echo esc_attr( $notice['type'] ); ?>"
			data-pph-lookup-notice="<?php echo esc_attr( $notice['type'] ); ?>"
			role="status"
		>
			<?php echo esc_html( $notice['message'] ); ?>
		</p>
	<?php endif; ?>

	<form
		class="pph-lookup__form"
		method="post"
		action="<?php echo esc_url( $action ); ?>"
		data-pph-lookup-form
	>
		<p class="pph-lookup__intro">
			<?php esc_html_e( 'Enter your order number and the email address you used at checkout. We will email a secure link to that order — no password needed.', 'wpmake-post-purchase-hub' ); ?>
		</p>

		<p class="pph-lookup__field">
			<label for="pph-lookup-number">
				<?php esc_html_e( 'Order number', 'wpmake-post-purchase-hub' ); ?>
			</label>
			<input
				type="text"
				id="pph-lookup-number"
				name="<?php echo esc_attr( $fields['number'] ); ?>"
				class="pph-lookup__input input-text"
				autocomplete="off"
				spellcheck="false"
				required
				data-pph-lookup-number
			/>
		</p>

		<p class="pph-lookup__field">
			<label for="pph-lookup-email">
				<?php esc_html_e( 'Billing email', 'wpmake-post-purchase-hub' ); ?>
			</label>
			<input
				type="email"
				id="pph-lookup-email"
				name="<?php echo esc_attr( $fields['email'] ); ?>"
				class="pph-lookup__input input-text"
				autocomplete="email"
				required
				data-pph-lookup-email
			/>
		</p>

		<?php
		/**
		 * Fires inside the lookup form, before its submit button.
		 *
		 * Where a bot-challenge plugin renders its widget. Pair it with the
		 * `pph_lookup_challenge` filter, which is what decides whether an
		 * attempt is rejected — markup alone stops nothing.
		 *
		 * @since 0.11.0
		 */
		do_action( 'pph_lookup_form_fields' );
		?>

		<p class="pph-lookup__actions">
			<button
				type="submit"
				class="pph-lookup__submit button"
				name="<?php echo esc_attr( $fields['submit'] ); ?>"
				value="1"
				data-pph-lookup-submit
			>
				<?php esc_html_e( 'Email me a secure link', 'wpmake-post-purchase-hub' ); ?>
			</button>
		</p>
	</form>
</div>
