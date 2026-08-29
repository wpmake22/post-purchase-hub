<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Requires the WordPress core test library and a WooCommerce install, both of
 * which wp-env provides. Run the suite twice — once with HPOS enabled and once
 * with it disabled — because every order read and write must behave identically
 * under both storage engines.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

$wpmphub_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $wpmphub_autoload ) ) {
	echo "Composer autoloader missing. Run: composer install\n";
	exit( 1 );
}

require_once $wpmphub_autoload;

// Constant name is defined by the WordPress test library, so it cannot carry our prefix.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

$wpmphub_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $wpmphub_tests_dir ) || '' === $wpmphub_tests_dir ) {
	$wpmphub_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$wpmphub_tests_dir = rtrim( $wpmphub_tests_dir, '/\\' );

if ( ! file_exists( $wpmphub_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$wpmphub_tests_dir}.\n";
	echo "Start wp-env (npx wp-env start) or set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $wpmphub_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce and this plugin before WordPress finishes booting.
 *
 * @return void
 */
function wpmphub_tests_load_plugins(): void {
	$wpmphub_woo_file = getenv( 'WP_WOOCOMMERCE_FILE' );

	if ( ! is_string( $wpmphub_woo_file ) || '' === $wpmphub_woo_file ) {
		$wpmphub_woo_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}

	if ( ! file_exists( $wpmphub_woo_file ) ) {
		echo "WooCommerce not found at {$wpmphub_woo_file}. Set WP_WOOCOMMERCE_FILE.\n";
		exit( 1 );
	}

	require_once $wpmphub_woo_file;

	// The main plugin file is delivered by M01; until then the suite boots WooCommerce only.
	$wpmphub_plugin_file = dirname( __DIR__ ) . '/wpmake-post-purchase-hub.php';

	if ( file_exists( $wpmphub_plugin_file ) ) {
		require_once $wpmphub_plugin_file;
	}
}

/**
 * Installs WooCommerce tables and roles into the fresh test database, then
 * installs this plugin the way a real activation would.
 *
 * The plugin half matters more than it looks. `register_activation_hook()`
 * never fires in the test harness, so without this the suite runs against an
 * install that has no `wpmphub_token_secret` — and every code path that mints a
 * signed link fails for a reason no production site would ever hit. Two
 * integration failures were exactly that, and they masked the question of
 * whether the code under test was sound. An integration suite whose
 * environment is not a real install is testing the harness.
 *
 * WP_UnitTestCase rolls each test back in a transaction, so this runs once and
 * every test sees the same installed state.
 *
 * @return void
 */
function wpmphub_tests_install_woocommerce(): void {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	WC_Install::install();

	// Roles are cached before WooCommerce adds its own, so rebuild them.
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_roles();

	wpmphub_tests_install_plugin_options();
}

/**
 * Installs the options a real activation would have written.
 *
 * Deliberately not `Activator::activate()`. That would also create the custom
 * tables, and it runs outside the per-test lifecycle — where WP_UnitTestCase's
 * `query` filters rewrite `CREATE TABLE` to `CREATE TEMPORARY TABLE`. Real
 * tables created here would then be shadowed by each test's temporary ones,
 * and a test that drops its tables would see the real pair still standing,
 * which is how `UninstallTest` starts failing for a reason that has nothing to
 * do with uninstalling.
 *
 * What the harness was actually missing is the token secret:
 * `register_activation_hook()` never fires here, so the suite ran against an
 * install that could not mint a signed link, and every path that tried failed
 * for a reason no production site would ever hit. Schema installation already
 * happens where it belongs, inside the tests that need it.
 *
 * @return void
 */
function wpmphub_tests_install_plugin_options(): void {
	if ( ! class_exists( PostPurchaseHub\Install\Activator::class ) ) {
		return;
	}

	if ( '' === (string) get_option( PostPurchaseHub\Install\Activator::TOKEN_SECRET_OPTION, '' ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes for storage, as Activator does; not obfuscation.
		add_option( PostPurchaseHub\Install\Activator::TOKEN_SECRET_OPTION, base64_encode( random_bytes( 64 ) ), '', false );
	}
}

tests_add_filter( 'muplugins_loaded', 'wpmphub_tests_load_plugins' );
tests_add_filter( 'setup_theme', 'wpmphub_tests_install_woocommerce' );

require $wpmphub_tests_dir . '/includes/bootstrap.php';
