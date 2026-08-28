<?php
/**
 * Pro edition entry point.
 *
 * Present only in the Pro distribution; bin/build.php removes this directory
 * from the free zip. The main plugin file loads it behind is_readable(), so its
 * absence is not a condition core ever has to test for.
 *
 * @package PostPurchaseHub\Pro
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers Pro's bundled translations.
 *
 * Core does not call load_plugin_textdomain() at all: the free distribution is
 * hosted on WordPress.org, which has served translations for a plugin's own
 * text domain without that call since WordPress 4.6, and calling it there is
 * both redundant and flagged in review. Pro is not hosted there, so it ships
 * its own .mo files and has to register them itself — which is why this lives
 * on the Pro side of the line rather than behind an edition check in core.
 *
 * @since 1.0.0
 * @return void
 */
function pph_pro_load_textdomain(): void {
	load_plugin_textdomain( 'wpmake-post-purchase-hub', false, dirname( plugin_basename( PPH_PLUGIN_FILE ) ) . '/languages' );
}

add_action( 'init', 'pph_pro_load_textdomain' );

add_action(
	'pph_loaded',
	/**
	 * Attaches Pro to core once the container exists.
	 *
	 * @since 0.5.0
	 *
	 * @param \PostPurchaseHub\Plugin $plugin Core service container.
	 * @return void
	 */
	static function ( $plugin ): void {
		( new PostPurchaseHub\Pro\Bootstrap( $plugin ) )->register();
	}
);
