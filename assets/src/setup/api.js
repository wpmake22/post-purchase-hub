/**
 * The wizard's only conversation with the server.
 *
 * Every call goes through `@wordpress/api-fetch`, which WordPress has already
 * fitted with the REST root and the logged-in nonce by the time this bundle
 * runs — so there is no hand-rolled `fetch`, no nonce plumbed through the page,
 * and no second place for the credentials story to be got wrong.
 *
 * Each step has a route named after the question it asks, and every one of them
 * answers with the whole wizard state rather than an acknowledgement. That is
 * deliberate: answering the welcome screen can remove steps further along, so
 * the client never computes what comes next — it is told.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const settings = window.wpmphubSetup || {};

const ROUTE = '/wpmphub/v1/setup';

/**
 * The route each step saves to.
 *
 * @type {Object<string, string>}
 */
const ENDPOINTS = {
	welcome: '/welcome',
	statuses: '/statuses',
	delivery: '/delivery',
	tracking: '/tracking',
	actions: '/actions',
	display: '/display',
	finish: '/finish',
};

/**
 * The values each step sends, pulled out of the app's working copy.
 *
 * A step that asks nothing sends nothing — the route still exists so that
 * moving forward is one code path rather than one per screen.
 *
 * @param {string} step   Step id.
 * @param {Object} values The wizard's working answers.
 * @return {Object} Request body for that step.
 */
export function bodyFor( step, values ) {
	switch ( step ) {
		case 'welcome':
			return { path: values.path };
		case 'statuses':
			return { status_map: values.statusMap };
		case 'delivery':
			// An emptied box is not an answer of zero and not a validation
			// error either — the parameter is left out, which the route reads
			// as "this question went unanswered" and leaves the default alone.
			return {
				...( '' === values.handlingDays ||
				null === values.handlingDays ||
				isNaN( Number( values.handlingDays ) )
					? {}
					: { handling_days: Number( values.handlingDays ) } ),
				handling_overrides: values.handlingOverrides,
			};
		case 'actions':
			return { enabled_actions: values.enabledActions };
		case 'display':
			return { template_mode: values.templateMode };
		default:
			return {};
	}
}

/**
 * Reads the wizard's state.
 *
 * @return {Promise<Object>} Current path, step, visible steps and drafts.
 */
export function fetchState() {
	return apiFetch( { path: ROUTE } );
}

/**
 * Reads the reference data every screen draws from — this store's statuses,
 * shipping methods, detections and preview.
 *
 * @return {Promise<Object>} Context payload.
 */
export function fetchContext() {
	return apiFetch( { path: `${ ROUTE }/context` } );
}

/**
 * Saves one step's answers and moves on.
 *
 * @param {string} step   Step being answered.
 * @param {Object} values The wizard's working answers.
 * @return {Promise<Object>} The new wizard state.
 */
export function saveStep( step, values ) {
	return apiFetch( {
		path: ROUTE + ( ENDPOINTS[ step ] || '' ),
		method: 'POST',
		data: bodyFor( step, values ),
	} );
}

/**
 * Moves on without answering, which is what leaves a setting on its default.
 *
 * @param {string} step Step being skipped.
 * @return {Promise<Object>} The new wizard state.
 */
export function skipStep( step ) {
	return apiFetch( {
		path: `${ ROUTE }/skip`,
		method: 'POST',
		data: { step },
	} );
}

/**
 * Commits every draft and brings the storefront up.
 *
 * @return {Promise<Object>} The new wizard state, with `completed` set.
 */
export function finishSetup() {
	return apiFetch( { path: `${ ROUTE }/finish`, method: 'POST' } );
}

/**
 * A server error turned into a sentence worth showing a merchant.
 *
 * @param {Object} error Whatever the request rejected with.
 * @return {string} Message to display.
 */
export function messageFor( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}

	return __(
		'Something went wrong saving that. Nothing has been changed on your store — try again.',
		'wpmake-post-purchase-hub'
	);
}

export { settings };
