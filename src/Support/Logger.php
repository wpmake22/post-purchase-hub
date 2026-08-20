<?php
/**
 * Logging façade over WC_Logger.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Support;

/**
 * Writes to WooCommerce's log store under this plugin's own source.
 *
 * Going through WC_Logger rather than error_log() means merchants read our
 * output in WooCommerce → Status → Logs, and log retention, levels and handlers
 * stay theirs to configure.
 *
 * @since 0.1.0
 */
final class Logger {

	/**
	 * Log source, which is also the log file name WooCommerce writes to.
	 *
	 * @var string
	 */
	public const SOURCE = 'post-purchase-hub';

	/**
	 * Memoised WooCommerce logger.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private ?\WC_Logger_Interface $logger = null;

	/**
	 * Logs a message a developer would want while debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Logs a message describing normal but notable activity.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Logs a recoverable problem, such as data we fell back from.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Logs a failure a merchant may need to act on.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Logs at an explicit WooCommerce log level.
	 *
	 * Silently does nothing when WooCommerce is unavailable: logging must never
	 * be the reason a request fails.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $level   One of the WC_Log_Levels values.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Additional context.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$logger = $this->logger();

		if ( null === $logger ) {
			return;
		}

		$context['source'] = self::SOURCE;

		$logger->log( $level, $message, $context );
	}

	/**
	 * Resolves WooCommerce's logger once.
	 *
	 * @since 0.1.0
	 *
	 * @return \WC_Logger_Interface|null
	 */
	private function logger(): ?\WC_Logger_Interface {
		if ( null === $this->logger && function_exists( 'wc_get_logger' ) ) {
			$this->logger = wc_get_logger();
		}

		return $this->logger;
	}
}
