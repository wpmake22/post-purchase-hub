<?php
/**
 * WC_Logger_Interface stub for the unit suite.
 *
 * Just enough of the interface Support\Logger type-hints against. Guarded,
 * so loading this where the real WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedInterfaceFound -- This stub must carry the WooCommerce name it replaces.

if ( ! interface_exists( 'WC_Logger_Interface' ) ) {
	/**
	 * Stand-in for WooCommerce's logger interface.
	 */
	interface WC_Logger_Interface {

		/**
		 * Logs a message at a given level.
		 *
		 * @param string               $level   Log level.
		 * @param string               $message Message.
		 * @param array<string, mixed> $context Additional context.
		 * @return void
		 */
		public function log( $level, $message, $context = array() );
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedInterfaceFound
