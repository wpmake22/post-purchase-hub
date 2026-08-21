<?php
/**
 * WP_User stub for the unit suite.
 *
 * Just enough of the shape get_userdata() hands back: a display name, which
 * is all the admin order note this milestone writes needs to read.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Stand-in for a WordPress user.
	 */
	class WP_User {

		/**
		 * User id.
		 *
		 * @var int
		 */
		public int $ID;

		/**
		 * Display name.
		 *
		 * @var string
		 */
		public string $display_name;

		/**
		 * Constructor.
		 *
		 * @param int    $id           User id.
		 * @param string $display_name Display name.
		 */
		public function __construct( int $id, string $display_name ) {
			$this->ID           = $id;
			$this->display_name = $display_name;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
