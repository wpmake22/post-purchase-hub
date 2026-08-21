<?php
/**
 * WP_Screen stub for the unit suite.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * The one property this plugin reads: which admin screen this is.
	 *
	 * `Admin\Notices` decides whether to draw itself from the screen id, so a
	 * test that wants to place itself on the plugins screen or the dashboard
	 * only needs that much of core's own class.
	 */
	final class WP_Screen {

		/**
		 * Screen id.
		 *
		 * @var string
		 */
		public string $id;

		/**
		 * Constructor.
		 *
		 * @param string $id Screen id.
		 */
		public function __construct( string $id = '' ) {
			$this->id = $id;
		}
	}
}
