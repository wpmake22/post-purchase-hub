/**
 * Build configuration for Post-Purchase Hub for WooCommerce.
 *
 * @wordpress/scripts derives its entry points from block.json once any block
 * exists in the source directory, which silently drops assets/src/index.js —
 * the shared frontend bundle that additive rendering depends on and that the
 * block does not. This restores it alongside the block entries rather than
 * moving shared styles inside a block folder, where WordPress would tie their
 * loading to that block appearing on the page.
 *
 * Output paths are set here rather than on the command line: `--output-path`
 * applies to every configuration in the array, so with it both bundles wrote to
 * the same directory and the Pro entry silently overwrote the core manifest.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const blockEntries =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

const core = {
	...defaultConfig,
	name: 'core',
	entry: {
		...blockEntries,
		index: path.resolve( process.cwd(), 'assets', 'src', 'index.js' ),
		requests: path.resolve(
			process.cwd(),
			'assets',
			'src',
			'js',
			'requests.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets', 'build' ),
	},
};

/**
 * The Pro edition's bundle.
 *
 * A separate configuration rather than another entry, because it has to land in
 * pro/assets/build: bin/build.php refuses to package the Pro zip when
 * pro/assets/src exists without a matching build, and shipping Pro's assets
 * inside the core build directory would put them in the free zip.
 */
const pro = {
	...defaultConfig,
	name: 'pro',
	entry: {
		index: path.resolve(
			process.cwd(),
			'pro',
			'assets',
			'src',
			'index.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'pro', 'assets', 'build' ),
	},
	// The default config copies block.json and PHP from assets/src. Those are
	// core's, and copying them here would put core's block metadata inside the
	// Pro bundle.
	plugins: defaultConfig.plugins.filter(
		( plugin ) => plugin.constructor.name !== 'CopyPlugin'
	),
};

module.exports = [ core, pro ];
