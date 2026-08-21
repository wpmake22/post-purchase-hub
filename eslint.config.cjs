/**
 * ESLint flat config for Post-Purchase Hub for WooCommerce.
 *
 * `wp-scripts lint-js` only falls back to its own default config when a
 * project provides none, so this extends it rather than replacing it.
 * `assets/src/js/requests.js` is the first vanilla, browser-DOM script this
 * project ships — every other bundled file is block-editor JS, where
 * `@wordpress/eslint-plugin`'s recommended config assumes no direct
 * `HTMLElement`-family reference — so this is the one place that config
 * needs browser globals added.
 */

const globals = require( 'globals' );
const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpConfig,
	{
		files: [ 'assets/src/js/**/*.js', 'pro/assets/src/js/**/*.js' ],
		languageOptions: {
			globals: globals.browser,
		},
	},
];
