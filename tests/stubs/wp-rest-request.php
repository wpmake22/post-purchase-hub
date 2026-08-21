<?php
/**
 * WP_REST_Request stub for the unit suite.
 *
 * Just enough of a REST request to drive a controller directly: a bag of
 * already-sanitised params, exactly as the real class hands them to a
 * permission_callback and a callback once the schema has run. Guarded, so
 * loading this where WordPress exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stand-in for a REST request.
	 */
	class WP_REST_Request {

		/**
		 * Parameters.
		 *
		 * @var array<string, mixed>
		 */
		private array $params;

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $params Parameters, as if already sanitised by a schema.
		 */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		/**
		 * Returns one parameter, or null when it is not set.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Sets one parameter.
		 *
		 * A permission_callback stashes a value here — an already-loaded order,
		 * say — for the callback that runs after it to reuse without a second
		 * lookup or a second ownership check.
		 *
		 * @param string $key   Parameter name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * Returns every parameter.
		 *
		 * @return array<string, mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
