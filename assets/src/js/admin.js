/**
 * Admin behaviour: the confirmations, and the settings screen's navigation.
 *
 * Three settings can surprise a merchant after the fact — full replacement
 * changing every order page, guest access opening a public endpoint, and
 * delete-on-uninstall throwing away request history. Each declares its own
 * sentence in `SettingsFields`, arrives in the markup as `data-pph-confirm`,
 * and is confirmed here on submit.
 *
 * The rest is the settings screen being navigable: filtering the visible
 * settings as you type, and opening the sidebar on a narrow screen.
 *
 * All of it is enhancement, never the control. With no JavaScript the settings
 * still save and every field is still on the page — which is why guest access is
 * *also* refused server-side without its acknowledgement checkbox, and deletion
 * is checked again at uninstall time.
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

	/**
	 * Keeps the switch's own word — "On" or "Off" — honest as it is toggled.
	 *
	 * The label is rendered server-side from the stored value, so without this
	 * a merchant would flip a switch and read the opposite of what they just
	 * did until the page reloaded.
	 *
	 * @param {Event} event Change event.
	 * @return {void}
	 */
	function onSwitchChange( event ) {
		const input = event.target;

		if (
			! ( input instanceof HTMLInputElement ) ||
			'checkbox' !== input.type
		) {
			return;
		}

		const label = input.closest( '.pph-switch' );
		const text = label ? label.querySelector( '.pph-switch__label' ) : null;

		if ( ! text || ! text.hasAttribute( 'data-pph-switch-states' ) ) {
			return;
		}

		const states = (
			text.getAttribute( 'data-pph-switch-states' ) || ''
		).split( '|' );

		if ( 2 === states.length ) {
			text.textContent = input.checked ? states[ 0 ] : states[ 1 ];
		}
	}

	/**
	 * Filters the settings on the open tab down to those matching a query.
	 *
	 * Matching is against the label and the help text a field declared, carried
	 * in `data-pph-settings-terms` — not against the rendered markup, because
	 * that would also match the words inside a select's options and hand back
	 * rows a merchant did not ask for. A card with nothing left visible hides
	 * itself, so the result reads as a shorter page rather than a page of empty
	 * headings.
	 *
	 * @param {string} query What was typed.
	 * @return {void}
	 */
	function filterSettings( query ) {
		const cards = document.querySelectorAll(
			'[data-pph-settings-section]'
		);
		const needle = query.trim().toLowerCase();
		let matches = 0;

		for ( const card of cards ) {
			const rows = card.querySelectorAll( '[data-pph-settings-terms]' );
			let visible = 0;

			for ( const row of rows ) {
				const terms =
					row.getAttribute( 'data-pph-settings-terms' ) || '';
				const hit = '' === needle || terms.includes( needle );

				row.hidden = ! hit;

				if ( hit ) {
					visible += 1;
				}
			}

			// A card with no rows of its own — the health panel, the Emails
			// signpost — is matched on its own heading instead of vanishing the
			// moment anything is typed.
			if ( 0 === rows.length ) {
				const heading = card.querySelector( 'h3' );
				const text = heading ? heading.textContent.toLowerCase() : '';

				visible = '' === needle || text.includes( needle ) ? 1 : 0;
			}

			card.hidden = 0 === visible;
			matches += visible;
		}

		const empty = document.querySelector( '[data-pph-settings-empty]' );

		if ( empty ) {
			empty.hidden = 0 !== matches;
		}
	}

	/**
	 * Wires the settings screen, when this is the settings screen.
	 *
	 * @return {void}
	 */
	function bindSettings() {
		const screen = document.querySelector( '[data-pph-settings]' );

		if ( ! screen ) {
			return;
		}

		const search = screen.querySelector( '[data-pph-settings-search]' );

		if ( search instanceof HTMLInputElement ) {
			search.addEventListener( 'input', () =>
				filterSettings( search.value )
			);
		}

		const burger = screen.querySelector( '[data-pph-settings-burger]' );
		const sidebar = screen.querySelector( '[data-pph-settings-sidebar]' );

		if ( burger && sidebar ) {
			burger.addEventListener( 'click', () => {
				const open = sidebar.classList.toggle( 'is-open' );

				burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		}
	}

	document.addEventListener( 'submit', onSubmit );
	document.addEventListener( 'change', onSwitchChange );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', bindSettings );
	} else {
		bindSettings();
	}
} )();
