<?php
/**
 * WP_REST_Response stub for the unit suite.
 *
 * Guarded, so loading this where WordPress exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stand-in for a REST response.
	 */
	class WP_REST_Response {

		/**
		 * Response body.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * HTTP status.
		 *
		 * @var int
		 */
		private int $status;

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Response body.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Returns the response body.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Returns the HTTP status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
