<?php
/**
 * WP_Post stub for the unit suite.
 *
 * Guarded, so loading this where WordPress exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stand-in for a WordPress post, carrying only what asset scoping reads.
	 */
	class WP_Post {

		/**
		 * Post content.
		 *
		 * @var string
		 */
		public string $post_content;

		/**
		 * Constructor.
		 *
		 * @param string $post_content Post content.
		 */
		public function __construct( string $post_content = '' ) {
			$this->post_content = $post_content;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
