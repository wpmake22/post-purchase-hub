/**
 * Build configuration for Post-Purchase Hub for WooCommerce.
 *
 * @wordpress/scripts derives its entry points from block.json once any block
 * exists in the source directory, which silently drops assets/src/index.js —
 * the shared frontend bundle that additive rendering depends on and that the
 * block does not. This restores it alongside the block entries rather than
 * moving shared styles inside a block folder, where WordPress would tie their
 * loading to that block appearing on the page.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const blockEntries =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...blockEntries,
		index: path.resolve( process.cwd(), 'assets', 'src', 'index.js' ),
	},
};
