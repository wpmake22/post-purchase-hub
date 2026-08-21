<?php
/**
 * Free edition entry point.
 *
 * Present only in the free distribution; bin/build.php removes this directory
 * from the Pro zip, where the real features stand where its teasers did.
 *
 * @package PostPurchaseHub\Free
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'pph_loaded',
	/**
	 * Attaches the free edition's upsell surfaces once the container exists.
	 *
	 * @since 0.5.0
	 *
	 * @param \PostPurchaseHub\Plugin $plugin Core service container.
	 * @return void
	 */
	static function ( $plugin ): void {
		( new PostPurchaseHub\Free\Bootstrap( $plugin ) )->register();
	}
);
