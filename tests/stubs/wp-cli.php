<?php
/**
 * WP-CLI stub for the integration suite.
 *
 * PHPUnit does not run under WP-CLI, so the command classes have nothing to
 * report to. This captures their output instead, letting the tests assert on
 * what a merchant would have seen. Guarded, so a real WP-CLI wins.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WP-CLI name it replaces.

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Collects what a command would have printed.
	 */
	class WP_CLI {

		/**
		 * Every line the command emitted, in order.
		 *
		 * @var list<string>
		 */
		public static array $output = array();

		/**
		 * Clears the captured output.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$output = array();
		}

		/**
		 * Records an informational line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ): void {
			self::$output[] = 'log: ' . $message;
		}

		/**
		 * Records a success line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ): void {
			self::$output[] = 'success: ' . $message;
		}

		/**
		 * Records a warning line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ): void {
			self::$output[] = 'warning: ' . $message;
		}

		/**
		 * Aborts the command, the way WP-CLI's own error() does.
		 *
		 * @param string $message Message.
		 * @return void
		 * @throws \RuntimeException Always.
		 */
		public static function error( $message ): void {
			throw new \RuntimeException( esc_html( (string) $message ) );
		}

		/**
		 * Accepts the prompt unconditionally.
		 *
		 * @param string $question Question.
		 * @return void
		 */
		public static function confirm( $question ): void {
			self::$output[] = 'confirm: ' . $question;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
