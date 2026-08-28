<?php
/**
 * WPMake Post-Purchase Hub for WooCommerce
 *
 * @package PostPurchaseHub
 *
 * @wordpress-plugin
 * Plugin Name:          WPMake Post-Purchase Hub for WooCommerce
 * Description:          Order timeline, self-service post-purchase actions and a merchant request queue for WooCommerce stores.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               WPMake
 * Author URI:           https://wpmake.net/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wpmake-post-purchase-hub
 * Domain Path:          /languages
 * WC requires at least: 10.9
 * WC tested up to:      11.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The integration test bootstrap loads this file directly; WordPress may then load it again as an active plugin.
if ( defined( 'PPH_VERSION' ) ) {
	return;
}

define( 'PPH_VERSION', '1.0.0' );
define( 'PPH_PLUGIN_FILE', __FILE__ );
define( 'PPH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPH_MINIMUM_PHP', '8.1' );
define( 'PPH_MINIMUM_WP', '6.5' );
define( 'PPH_MINIMUM_WC', '10.9' );

/*
 * Rewritten by bin/build.php when it stages each zip. The source value is
 * 'free' so a checkout behaves like the distribution most people run, and so a
 * mistake here fails open into the smaller feature set rather than the larger.
 */
define( 'PPH_EDITION', 'free' );

if ( is_readable( PPH_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PPH_PLUGIN_DIR . 'vendor/autoload.php';
}

// Guarded because a source checkout without `composer install` has no classes to call.
if ( class_exists( PostPurchaseHub\Install\Activator::class ) ) {
	register_activation_hook( __FILE__, array( PostPurchaseHub\Install\Activator::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( PostPurchaseHub\Install\Deactivator::class, 'deactivate' ) );
}

add_action( 'plugins_loaded', 'pph_bootstrap' );

/**
 * Boots the plugin once WordPress has loaded every plugin, or bails with a notice.
 *
 * Nothing else in this plugin runs before this function decides the environment
 * is supportable, so an unsupported site gets one admin notice and no behaviour.
 *
 * @since 0.1.0
 *
 * @return void
 */
function pph_bootstrap(): void {
	$failures = pph_requirement_failures(
		PHP_VERSION,
		(string) get_bloginfo( 'version' ),
		defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
		class_exists( PostPurchaseHub\Plugin::class )
	);

	if ( array() !== $failures ) {
		add_action(
			'admin_notices',
			static function () use ( $failures ): void {
				pph_requirements_notice( $failures );
			}
		);

		return;
	}

	// Registered here rather than at file load so an unsupported site declares nothing at all.
	add_action( 'before_woocommerce_init', 'pph_declare_hpos_compatibility' );

	// Before register(), which is what fires `pph_loaded`: an edition that
	// attaches after that hook has already fired would never run.
	pph_load_edition();

	PostPurchaseHub\Plugin::instance()->register();
}

/**
 * Loads whichever edition bootstrap survived the build.
 *
 * The two distributions are one source tree with a directory removed, so which
 * one is installed is a question about the filesystem rather than about a
 * constant. Both files are optional and neither is named in any type
 * declaration, which is what keeps a stripped directory from being a fatal.
 *
 * @since 0.5.0
 *
 * @return void
 */
function pph_load_edition(): void {
	foreach ( array( 'pro/bootstrap.php', 'free/bootstrap.php' ) as $pph_bootstrap ) {
		$pph_path = PPH_PLUGIN_DIR . $pph_bootstrap;

		if ( is_readable( $pph_path ) ) {
			require_once $pph_path;
		}
	}
}

/**
 * Whether this install is the Pro distribution.
 *
 * For edition code and for third parties. Nothing under `src/` may call it:
 * core registers extension points and the editions fill them, so a core file
 * asking which edition it is in is a sign the extension point is missing. CI
 * greps for that.
 *
 * @since 0.5.0
 *
 * @return bool
 */
function pph_is_pro(): bool {
	return defined( 'PPH_EDITION' ) && 'pro' === PPH_EDITION;
}

/**
 * Lists the unmet requirements for the given environment.
 *
 * Kept free of globals so the guard itself is testable: every input arrives as
 * an argument and the return value is the whole decision.
 *
 * @since 0.1.0
 *
 * @param string      $php_version Running PHP version.
 * @param string      $wp_version  Running WordPress version.
 * @param string|null $wc_version  Running WooCommerce version, or null when WooCommerce is not loaded.
 * @param bool        $autoloaded  Whether the Composer autoloader made the plugin classes available.
 * @return string[] Human-readable failures, empty when every requirement is met.
 */
function pph_requirement_failures( string $php_version, string $wp_version, ?string $wc_version, bool $autoloaded = true ): array {
	$failures = array();

	if ( version_compare( $php_version, PPH_MINIMUM_PHP, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: minimum required PHP version, 2: PHP version running on this site */
			__( 'WPMake Post-Purchase Hub for WooCommerce requires PHP %1$s or later. This site runs PHP %2$s.', 'wpmake-post-purchase-hub' ),
			PPH_MINIMUM_PHP,
			$php_version
		);
	}

	if ( version_compare( $wp_version, PPH_MINIMUM_WP, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: minimum required WordPress version, 2: WordPress version running on this site */
			__( 'WPMake Post-Purchase Hub for WooCommerce requires WordPress %1$s or later. This site runs WordPress %2$s.', 'wpmake-post-purchase-hub' ),
			PPH_MINIMUM_WP,
			$wp_version
		);
	}

	if ( null === $wc_version ) {
		$failures[] = __( 'WPMake Post-Purchase Hub for WooCommerce requires WooCommerce to be installed and active.', 'wpmake-post-purchase-hub' );
	} elseif ( version_compare( $wc_version, PPH_MINIMUM_WC, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: minimum required WooCommerce version, 2: WooCommerce version running on this site */
			__( 'WPMake Post-Purchase Hub for WooCommerce requires WooCommerce %1$s or later. This site runs WooCommerce %2$s.', 'wpmake-post-purchase-hub' ),
			PPH_MINIMUM_WC,
			$wc_version
		);
	}

	if ( ! $autoloaded ) {
		$failures[] = __( 'WPMake Post-Purchase Hub for WooCommerce is missing its autoloader. Reinstall the plugin from a release package.', 'wpmake-post-purchase-hub' );
	}

	return $failures;
}

/**
 * Prints the unmet-requirements notice for users who can act on it.
 *
 * @since 0.1.0
 *
 * @param string[] $failures Unmet requirements, as returned by pph_requirement_failures().
 * @return void
 */
function pph_requirements_notice( array $failures ): void {
	if ( array() === $failures || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo implode( '<br />', array_map( 'esc_html', $failures ) );
	echo '</p></div>';
}

/**
 * Declares compatibility with WooCommerce's High-Performance Order Storage.
 *
 * Must run inside a `before_woocommerce_init` handler; WooCommerce ignores the
 * declaration otherwise and shows the store an incompatibility warning.
 *
 * @since 0.1.0
 *
 * @return void
 */
function pph_declare_hpos_compatibility(): void {
	if ( class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PPH_PLUGIN_FILE, true );
	}
}
