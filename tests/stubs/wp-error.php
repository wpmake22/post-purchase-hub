<?php
/**
 * WP_Error stub for the unit suite.
 *
 * Just enough of WP_Error to assert what a REST controller returned: the
 * first error code, its message and its data. Guarded, so loading this where
 * WordPress exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WordPress name it replaces.

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for WordPress's error carrier.
	 */
	class WP_Error {

		/**
		 * Messages, keyed by error code.
		 *
		 * @var array<string, list<string>>
		 */
		private array $errors = array();

		/**
		 * Data, keyed by error code.
		 *
		 * @var array<string, mixed>
		 */
		private array $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Returns the first error code, or an empty string when there are none.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		/**
		 * Returns the first message for a code, or the first error's message.
		 *
		 * @param string $code Error code. Empty for the first error.
		 * @return string
		 */
		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * Returns the data for a code, or the first error's data.
		 *
		 * @param string $code Error code. Empty for the first error.
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->error_data[ $code ] ?? '';
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
