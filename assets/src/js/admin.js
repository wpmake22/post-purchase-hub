/**
 * Admin behaviour: the confirmations, and nothing else.
 *
 * Three settings can surprise a merchant after the fact — full replacement
 * changing every order page, guest access opening a public endpoint, and
 * delete-on-uninstall throwing away request history. Each declares its own
 * sentence in `SettingsFields`, arrives in the markup as `data-pph-confirm`,
 * and is confirmed here on submit.
 *
 * This is an enhancement, never the control: with no JavaScript the settings
 * still save, which is why guest access is *also* refused server-side without
 * its acknowledgement checkbox and deletion is checked again at uninstall time.
 */

import './../styles/admin.scss';

( function () {
	'use strict';

	/**
	 * Whether one control is currently set to the value it wants confirming.
	 *
	 * @param {HTMLElement} control Control carrying the data attributes.
	 * @return {boolean} True when the confirmation applies right now.
	 */
	function needsConfirmation( control ) {
		const trigger = control.getAttribute( 'data-pph-confirm-value' );

		if (
			control instanceof HTMLInputElement &&
			'checkbox' === control.type
		) {
			return control.checked && '1' === trigger;
		}

		if (
			control instanceof HTMLSelectElement ||
			control instanceof HTMLInputElement
		) {
			return control.value === trigger;
		}

		return false;
	}

	/**
	 * Asks before letting a form with a pending confirmation submit.
	 *
	 * @param {SubmitEvent} event Submit event.
	 * @return {void}
	 */
	function onSubmit( event ) {
		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		const controls = form.querySelectorAll( '[data-pph-confirm]' );

		for ( const control of controls ) {
			if ( ! ( control instanceof HTMLElement ) ) {
				continue;
			}

			if ( ! needsConfirmation( control ) ) {
				continue;
			}

			const message = control.getAttribute( 'data-pph-confirm' ) || '';

			// eslint-disable-next-line no-alert
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();

				if (
					control instanceof HTMLInputElement &&
					'checkbox' === control.type
				) {
					control.checked = false;
				}

				control.focus();

				return;
			}
		}
	}

	document.addEventListener( 'submit', onSubmit );
} )();
