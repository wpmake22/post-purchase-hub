/**
 * Progressive enhancement for the guest order-lookup form.
 *
 * The form works without this file: it posts to its own page and the server
 * answers with a redirect. All this adds is answering in place instead of
 * reloading. Anything it cannot do — deciding whether an order exists, deciding
 * what to say about it — is deliberately absent, because the response is
 * identical for every outcome and there is nothing here to branch on.
 */

const SETTINGS = window.pphLookup || {};

/**
 * Replaces a form's notice with a message.
 *
 * @param {HTMLFormElement} form    The lookup form.
 * @param {string}          message Message to show.
 * @param {string}          type    Notice type, for styling only.
 */
function showNotice( form, message, type ) {
	const container = form.closest( '[data-pph-lookup]' ) || form.parentNode;

	let notice = container.querySelector( '[data-pph-lookup-notice]' );

	if ( ! notice ) {
		notice = document.createElement( 'p' );
		notice.setAttribute( 'role', 'status' );
		container.insertBefore( notice, container.firstChild );
	}

	notice.className = `pph-lookup__notice pph-lookup__notice--${ type }`;
	notice.setAttribute( 'data-pph-lookup-notice', type );
	notice.textContent = message;
}

/**
 * Submits one lookup attempt over the REST route.
 *
 * @param {SubmitEvent} event Submit event.
 */
async function submit( event ) {
	const form = event.target;

	if ( ! SETTINGS.restUrl ) {
		return;
	}

	event.preventDefault();

	const number = form.querySelector( '[data-pph-lookup-number]' );
	const email = form.querySelector( '[data-pph-lookup-email]' );

	if ( ! number || ! email ) {
		return;
	}

	const button = form.querySelector( '[data-pph-lookup-submit]' );

	if ( button ) {
		button.disabled = true;
	}

	let message = SETTINGS.errorMessage || '';
	let type = 'error';

	try {
		const response = await window.fetch( SETTINGS.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				order_number: number.value,
				email: email.value,
			} ),
		} );

		const body = await response.json();

		if ( body && body.message ) {
			message = body.message;
		}

		if ( response.ok ) {
			type = 'info';
			form.reset();
		}
	} catch {
		// Network failure only. Nothing about the order is knowable here, and
		// the fallback message says nothing about one either.
	}

	if ( button ) {
		button.disabled = false;
	}

	if ( message ) {
		showNotice( form, message, type );
	}
}

document.addEventListener( 'submit', ( event ) => {
	if (
		event.target instanceof HTMLFormElement &&
		event.target.matches( '[data-pph-lookup-form]' )
	) {
		submit( event );
	}
} );
