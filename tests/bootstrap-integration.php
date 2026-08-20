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

$pph_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $pph_autoload ) ) {
	echo "Composer autoloader missing. Run: composer install\n";
	exit( 1 );
}

require_once $pph_autoload;

// Constant name is defined by the WordPress test library, so it cannot carry our prefix.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

$pph_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $pph_tests_dir ) || '' === $pph_tests_dir ) {
	$pph_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$pph_tests_dir = rtrim( $pph_tests_dir, '/\\' );

if ( ! file_exists( $pph_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$pph_tests_dir}.\n";
	echo "Start wp-env (npx wp-env start) or set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $pph_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce and this plugin before WordPress finishes booting.
 *
 * @return void
 */
function pph_tests_load_plugins(): void {
	$pph_woo_file = getenv( 'WP_WOOCOMMERCE_FILE' );

	if ( ! is_string( $pph_woo_file ) || '' === $pph_woo_file ) {
		$pph_woo_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}

	if ( ! file_exists( $pph_woo_file ) ) {
		echo "WooCommerce not found at {$pph_woo_file}. Set WP_WOOCOMMERCE_FILE.\n";
		exit( 1 );
	}

	require_once $pph_woo_file;

	// The main plugin file is delivered by M01; until then the suite boots WooCommerce only.
	$pph_plugin_file = dirname( __DIR__ ) . '/post-purchase-hub.php';

	if ( file_exists( $pph_plugin_file ) ) {
		require_once $pph_plugin_file;
	}
}

/**
 * Installs WooCommerce tables and roles into the fresh test database.
 *
 * @return void
 */
function pph_tests_install_woocommerce(): void {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	WC_Install::install();

	// Roles are cached before WooCommerce adds its own, so rebuild them.
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_roles();
}

tests_add_filter( 'muplugins_loaded', 'pph_tests_load_plugins' );
tests_add_filter( 'setup_theme', 'pph_tests_install_woocommerce' );

require $pph_tests_dir . '/includes/bootstrap.php';
