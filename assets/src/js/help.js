/**
 * Help form submission.
 *
 * The form and everything attached to it are server-rendered, so a customer
 * with no JavaScript still reads what the store will be told. What this file
 * adds is the submission itself, over POST to `Rest\HelpController`, plus
 * opening the disclosure when a link from the orders list arrives with its
 * fragment.
 *
 * Reads its configuration from `window.wpmphubHelp`, localised by
 * Frontend\Assets: `restUrl` and `nonce`.
 */

import { __ } from '@wordpress/i18n';

( function () {
	'use strict';

	/** @type {{restUrl: string, nonce: string}} */
	const config = window.wpmphubHelp || { restUrl: '', nonce: '' };

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
	 * Replaces the form with the store's confirmation.
	 *
	 * @param {HTMLElement}     panel   Panel holding the form.
	 * @param {HTMLFormElement} form    Submitted form.
	 * @param {string}          message Confirmation text.
	 * @return {void}
	 */
	function confirm( panel, form, message ) {
		form.hidden = true;

		const success = document.createElement( 'p' );

		success.className = 'wpmphub-help__success';
		success.setAttribute( 'data-wpmphub-help-success', '' );
		success.setAttribute( 'role', 'status' );
		success.textContent = message;

		panel.appendChild( success );
	}

	/**
	 * The message a failed submission should show.
	 *
	 * @param {{message?: string, data?: {reference?: string}}} data Response body.
	 * @return {string} Message to show, with the support reference appended when there is one.
	 */
	function errorMessage( data ) {
		const message =
			data && 'string' === typeof data.message && data.message
				? data.message
				: __(
						'That could not be sent. Please try again.',
						'wpmake-post-purchase-hub'
				  );

		const reference =
			data && data.data && 'string' === typeof data.data.reference
				? data.data.reference
				: '';

		return reference ? message + ' (' + reference + ')' : message;
	}

	/**
	 * Submits one form.
	 *
	 * @param {SubmitEvent} event Submit event.
	 * @return {void}
	 */
	function onSubmit( event ) {
		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		event.preventDefault();

		const error = form.querySelector( '[data-wpmphub-help-error]' );
		const message = form.querySelector( '[data-wpmphub-help-message]' );

		hideError( error );

		if (
			! ( message instanceof HTMLTextAreaElement ) ||
			'' === message.value.trim()
		) {
			showError(
				error,
				__(
					'Please tell us what you need help with.',
					'wpmake-post-purchase-hub'
				)
			);

			if ( message instanceof HTMLTextAreaElement ) {
				message.focus();
			}

			return;
		}

		const panel = form.parentElement;
		const submit = form.querySelector( '[data-wpmphub-help-submit]' );
		const orderId = form.querySelector( '[data-wpmphub-help-order-id]' );
		const topic = form.querySelector( '[data-wpmphub-help-topic]' );

		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
		}

		const body = {
			order_id: orderId instanceof HTMLInputElement ? orderId.value : '',
			topic: topic instanceof HTMLSelectElement ? topic.value : '',
			message: message.value,
		};

		fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( body ),
		} )
			.then( ( response ) =>
				response
					.json()
					.then( ( data ) => ( { ok: response.ok, data } ) )
			)
			.then( ( { ok, data } ) => {
				if ( ok && panel instanceof HTMLElement ) {
					confirm(
						panel,
						form,
						'string' === typeof data.message && data.message
							? data.message
							: __(
									'Thanks — your message is on its way to the store.',
									'wpmake-post-purchase-hub'
							  )
					);

					return;
				}

				showError( error, errorMessage( data ) );

				if ( submit instanceof HTMLButtonElement ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				}
			} )
			.catch( () => {
				showError( error, errorMessage( {} ) );

				if ( submit instanceof HTMLButtonElement ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				}
			} );
	}

	/**
	 * Opens the disclosure a link from the orders list pointed at.
	 *
	 * Browsers scroll to a targeted `<details>` without opening it, so a
	 * customer who clicked "Get help with this order" on the orders list would
	 * otherwise land on a collapsed form.
	 *
	 * @return {void}
	 */
	function openTargeted() {
		const hash = window.location.hash;

		if ( ! hash || hash.length < 2 ) {
			return;
		}

		const target = document.querySelector(
			'[data-wpmphub-help]' + hash.replace( /[^\w#-]/g, '' )
		);

		if ( target instanceof HTMLDetailsElement ) {
			target.open = true;
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		if (
			event.target instanceof HTMLFormElement &&
			event.target.matches( '[data-wpmphub-help-form]' )
		) {
			onSubmit( event );
		}
	} );

	window.addEventListener( 'hashchange', openTargeted );
	openTargeted();
} )();
