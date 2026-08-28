/**
 * Reorder confirmation.
 *
 * The reconciliation summary itself is server-rendered, so everything the
 * customer needs to read is there without this file. What it adds is the one
 * thing a link cannot do: confirming over POST, because filling a cart from a
 * GET is exactly what this feature exists not to do.
 *
 * Reads its configuration from `window.pphReorder`, localised by
 * Frontend\Assets: `restUrl` and `nonce`.
 */

import { __, sprintf } from '@wordpress/i18n';

( function () {
	'use strict';

	/** @type {{restUrl: string, nonce: string}} */
	const config = window.pphReorder || { restUrl: '', nonce: '' };

	/**
	 * Shows the inline error, with the support reference when there is one.
	 *
	 * @param {HTMLElement|null} element Error element.
	 * @param {string}           message Message to show.
	 * @return {void}
	 */
	function showError( element, message ) {
		if ( ! ( element instanceof HTMLElement ) ) {
			return;
		}

		element.hidden = false;
		element.textContent = message;
	}

	/**
	 * Hides the inline error.
	 *
	 * @param {HTMLElement|null} element Error element.
	 * @return {void}
	 */
	function hideError( element ) {
		if ( ! ( element instanceof HTMLElement ) ) {
			return;
		}

		element.hidden = true;
		element.textContent = '';
	}

	/**
	 * The mode the customer chose, or the one the form carries when the cart
	 * was empty and no choice was offered.
	 *
	 * @param {HTMLFormElement} form Confirmation form.
	 * @return {string} 'merge' or 'replace'.
	 */
	function chosenMode( form ) {
		const checked = form.querySelector( '[data-pph-reorder-mode]:checked' );

		if ( checked instanceof HTMLInputElement ) {
			return checked.value;
		}

		const fallback = form.querySelector(
			'[data-pph-reorder-mode-default]'
		);

		return fallback instanceof HTMLInputElement ? fallback.value : 'merge';
	}

	/**
	 * Submits the confirmation.
	 *
	 * @param {SubmitEvent} event Submit event.
	 * @return {void}
	 */
	function onSubmit( event ) {
		event.preventDefault();

		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		const orderIdField = form.querySelector(
			'[data-pph-reorder-order-id]'
		);
		const orderId =
			orderIdField instanceof HTMLInputElement
				? parseInt( orderIdField.value, 10 )
				: 0;

		if ( ! orderId ) {
			return;
		}

		const error = form.querySelector( '[data-pph-reorder-error]' );
		const submit = form.querySelector( '[data-pph-reorder-confirm]' );

		hideError( error );

		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
		}

		fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( {
				order_id: orderId,
				mode: chosenMode( form ),
			} ),
		} )
			.then( ( response ) =>
				response
					.json()
					.then( ( data ) => ( { ok: response.ok, data } ) )
			)
			.then( ( { ok, data } ) => {
				if ( ok && data && data.cart_url ) {
					window.location.assign( data.cart_url );

					return;
				}

				onError( data, submit, error );
			} )
			.catch( () => {
				onError( {}, submit, error );
			} );
	}

	/**
	 * Re-enables the button and explains what went wrong.
	 *
	 * @param {{message?: string, data?: {reference?: string}}} data   Error body.
	 * @param {Element|null}                                    submit Submit button.
	 * @param {Element|null}                                    error  Error element.
	 * @return {void}
	 */
	function onError( data, submit, error ) {
		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = false;
			submit.removeAttribute( 'aria-busy' );
		}

		const message =
			data && data.message
				? data.message
				: __(
						'Your cart could not be updated. Please try again.',
						'wpmake-post-purchase-hub'
				  );
		const reference =
			data && data.data && data.data.reference ? data.data.reference : '';

		showError(
			error,
			reference
				? sprintf(
						/* translators: 1: error message, 2: support reference id. */
						__( '%1$s (reference: %2$s)', 'wpmake-post-purchase-hub' ),
						message,
						reference
				  )
				: message
		);
	}

	/**
	 * Wires the form, if this page carries one.
	 *
	 * @return {void}
	 */
	function init() {
		const form = document.querySelector( '[data-pph-reorder-form]' );

		if ( form instanceof HTMLFormElement ) {
			form.addEventListener( 'submit', onSubmit );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
