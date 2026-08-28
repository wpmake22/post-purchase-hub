/**
 * Cancellation-request modal behaviour.
 *
 * No framework: this is the one interactive surface the free plugin ships,
 * and a bundler-only dependency for a handful of DOM calls would be a heavier
 * cost than writing them out. `wp.i18n` is the one exception, loaded as a
 * WordPress script dependency rather than bundled, for the handful of strings
 * this file itself needs to show without a page reload.
 *
 * Reads its configuration from `window.pphRequests`, localised by
 * Frontend\Assets: `restUrl`, `nonce`, and `strings`.
 */

import { __, sprintf, _n } from '@wordpress/i18n';

( function () {
	'use strict';

	/** @type {{restUrl: string, nonce: string}} */
	const config = window.pphRequests || { restUrl: '', nonce: '' };

	const TRIGGER_SELECTOR = 'a[href^="#pph-cancel-"]';
	const FOCUSABLE_SELECTOR =
		'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

	let modal = null;
	let lastTrigger = null;

	/**
	 * Extracts the order id from a trigger link's href fragment.
	 *
	 * @param {HTMLAnchorElement} trigger Clicked trigger.
	 * @return {number} Order id, or 0 when it cannot be read.
	 */
	function orderIdFromTrigger( trigger ) {
		const match = /^#pph-cancel-(\d+)$/.exec(
			trigger.getAttribute( 'href' ) || ''
		);

		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	/**
	 * Opens the modal for one order.
	 *
	 * @param {HTMLAnchorElement} trigger Clicked trigger, so focus can return to it on close.
	 * @return {void}
	 */
	function openModal( trigger ) {
		if ( ! modal ) {
			return;
		}

		const orderId = orderIdFromTrigger( trigger );

		if ( ! orderId ) {
			return;
		}

		resetForm();

		const orderIdField = modal.querySelector(
			'[data-pph-request-order-id]'
		);

		if ( orderIdField ) {
			orderIdField.value = String( orderId );
		}

		lastTrigger = trigger;
		modal.hidden = false;
		modal.setAttribute( 'data-pph-modal-open', 'true' );

		const firstFocusable = modal.querySelector( FOCUSABLE_SELECTOR );

		if ( firstFocusable instanceof HTMLElement ) {
			firstFocusable.focus();
		}

		document.addEventListener( 'keydown', onKeydown, true );
	}

	/**
	 * Closes the modal and restores focus to whatever opened it.
	 *
	 * @return {void}
	 */
	function closeModal() {
		if ( ! modal || modal.hidden ) {
			return;
		}

		modal.hidden = true;
		modal.removeAttribute( 'data-pph-modal-open' );
		document.removeEventListener( 'keydown', onKeydown, true );

		if ( lastTrigger instanceof HTMLElement ) {
			lastTrigger.focus();
		}

		lastTrigger = null;
	}

	/**
	 * Traps focus inside the modal and closes it on Escape.
	 *
	 * @param {KeyboardEvent} event Keydown event.
	 * @return {void}
	 */
	function onKeydown( event ) {
		if ( ! modal || modal.hidden ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			closeModal();

			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		const focusable = Array.prototype.filter.call(
			modal.querySelectorAll( FOCUSABLE_SELECTOR ),
			( element ) => ! element.hidden
		);

		if ( 0 === focusable.length ) {
			return;
		}

		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		const active = modal.ownerDocument.activeElement;

		if ( event.shiftKey && active === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Clears the form back to its resting state.
	 *
	 * @return {void}
	 */
	function resetForm() {
		if ( ! modal ) {
			return;
		}

		const form = modal.querySelector( '[data-pph-request-form]' );
		const formError = modal.querySelector(
			'[data-pph-request-form-error]'
		);
		const reasonError = modal.querySelector(
			'[data-pph-request-reason-error]'
		);
		const submit = modal.querySelector( '[data-pph-request-submit]' );

		if ( form instanceof HTMLFormElement ) {
			form.hidden = false;
			form.reset();
		}

		hideError( formError );
		hideError( reasonError );

		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = false;
			submit.removeAttribute( 'aria-busy' );
		}

		const success = modal.querySelector( '[data-pph-request-success]' );

		if ( success instanceof HTMLElement ) {
			success.remove();
		}
	}

	/**
	 * Shows an inline error element with a message.
	 *
	 * @param {Element|null} element Error element.
	 * @param {string}       message Message to show.
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
	 * Hides an inline error element.
	 *
	 * @param {Element|null} element Error element.
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
	 * Submits the form.
	 *
	 * @param {SubmitEvent} event Submit event.
	 * @return {void}
	 */
	function onSubmit( event ) {
		event.preventDefault();

		if ( ! modal ) {
			return;
		}

		const form = event.target;
		const formError = modal.querySelector(
			'[data-pph-request-form-error]'
		);
		const reasonError = modal.querySelector(
			'[data-pph-request-reason-error]'
		);
		const reasonField = form.querySelector( '[data-pph-request-reason]' );

		hideError( formError );
		hideError( reasonError );

		if (
			reasonField instanceof HTMLSelectElement &&
			'' === reasonField.value
		) {
			showError(
				reasonError,
				__( 'Please choose a reason.', 'wpmake-post-purchase-hub' )
			);
			reasonField.focus();

			return;
		}

		const submit = modal.querySelector( '[data-pph-request-submit]' );

		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
		}

		const orderIdField = form.querySelector(
			'[data-pph-request-order-id]'
		);
		const noteField = form.querySelector( '[data-pph-request-note]' );

		const body = {
			order_id:
				orderIdField instanceof HTMLInputElement
					? orderIdField.value
					: '',
			reason_code:
				reasonField instanceof HTMLSelectElement
					? reasonField.value
					: '',
			note:
				noteField instanceof HTMLTextAreaElement ? noteField.value : '',
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
				if ( ok ) {
					onSuccess( data, parseInt( body.order_id, 10 ) );

					return;
				}

				onError( data, submit );
			} )
			.catch( () => {
				onError( {}, submit );
			} );
	}

	/**
	 * Handles a successful submission.
	 *
	 * @param {{expected_response_hours?: number}} data    Response body.
	 * @param {number}                             orderId Order the request was raised against.
	 * @return {void}
	 */
	function onSuccess( data, orderId ) {
		if ( ! modal ) {
			return;
		}

		const form = modal.querySelector( '[data-pph-request-form]' );
		const hours = Number( data.expected_response_hours ) || 24;

		if ( form instanceof HTMLFormElement ) {
			form.hidden = true;
		}

		const success = document.createElement( 'p' );

		success.setAttribute( 'data-pph-request-success', '' );
		success.setAttribute( 'role', 'status' );
		success.textContent = sprintf(
			/* translators: %d: expected response time in hours. */
			_n(
				'Your cancellation request has been received. We usually respond within %d hour.',
				'Your cancellation request has been received. We usually respond within %d hours.',
				hours,
				'wpmake-post-purchase-hub'
			),
			hours
		);

		modal.querySelector( '.pph-modal__panel' ).appendChild( success );

		updateTimeline( orderId, hours );
	}

	/**
	 * Reflects a newly pending request in an already-rendered timeline, on
	 * whichever page carries one for this order — the detail page's full
	 * timeline only; the orders list keeps showing the order's real status
	 * until reloaded, since finding a pending request out there would cost a
	 * query per row.
	 *
	 * @param {number} orderId Order the request was raised against.
	 * @param {number} hours   Expected response time in hours.
	 * @return {void}
	 */
	function updateTimeline( orderId, hours ) {
		const timeline = document.querySelector(
			'[data-pph-timeline][data-pph-order-id="' + orderId + '"]'
		);

		if ( ! timeline ) {
			return;
		}

		let branch = timeline.querySelector( '[data-pph-branch]' );

		if ( ! branch ) {
			branch = document.createElement( 'p' );
			branch.className =
				'pph-timeline__branch pph-timeline__branch--cancellation_requested';
			branch.setAttribute( 'data-pph-branch', 'cancellation_requested' );

			const label = document.createElement( 'strong' );

			label.className = 'pph-timeline__branch-label';
			label.setAttribute( 'data-pph-branch-label', '' );
			branch.appendChild( label );

			const note = document.createElement( 'span' );

			note.className = 'pph-timeline__branch-note';
			note.setAttribute( 'data-pph-branch-note', '' );
			branch.appendChild( note );

			timeline.appendChild( branch );
		}

		branch.setAttribute( 'data-pph-branch', 'cancellation_requested' );

		const label = branch.querySelector( '[data-pph-branch-label]' );
		const note = branch.querySelector( '[data-pph-branch-note]' );

		if ( label ) {
			label.textContent = __(
				'Cancellation requested',
				'wpmake-post-purchase-hub'
			);
		}

		if ( note ) {
			note.textContent = sprintf(
				/* translators: %d: expected response time in hours. */
				_n(
					'We usually respond within %d hour.',
					'We usually respond within %d hours.',
					hours,
					'wpmake-post-purchase-hub'
				),
				hours
			);
		}
	}

	/**
	 * Handles a rejected submission.
	 *
	 * @param {{message?: string, data?: {reference?: string}}} data   Error body.
	 * @param {Element|null}                                    submit Submit button, re-enabled here.
	 * @return {void}
	 */
	function onError( data, submit ) {
		if ( ! modal ) {
			return;
		}

		if ( submit instanceof HTMLButtonElement ) {
			submit.disabled = false;
			submit.removeAttribute( 'aria-busy' );
		}

		const formError = modal.querySelector(
			'[data-pph-request-form-error]'
		);
		const message =
			data && data.message
				? data.message
				: __(
						'Something went wrong. Please try again.',
						'wpmake-post-purchase-hub'
				  );
		const reference =
			data && data.data && data.data.reference ? data.data.reference : '';

		showError(
			formError,
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
	 * Wires every handler once the DOM is ready.
	 *
	 * @return {void}
	 */
	function init() {
		modal = document.querySelector( '[data-pph-request-modal]' );

		if ( ! modal ) {
			return;
		}

		document.addEventListener( 'click', ( event ) => {
			const trigger = event.target.closest( TRIGGER_SELECTOR );

			if ( trigger ) {
				event.preventDefault();
				openModal( trigger );

				return;
			}

			if (
				event.target.closest( '[data-pph-modal-close]' ) ||
				event.target.closest( '[data-pph-modal-backdrop]' )
			) {
				closeModal();
			}
		} );

		const form = modal.querySelector( '[data-pph-request-form]' );

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
