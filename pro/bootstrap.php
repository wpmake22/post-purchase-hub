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
