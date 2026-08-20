<?php
/**
 * Activation routine.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

/**
 * Prepares the options, tables and scheduled work the plugin needs.
 *
 * Runs in a request where the plugin was loaded but not bootstrapped, so it
 * takes no services and touches nothing outside its own options, its own tables
 * and its own cron hook.
 *
 * @since 0.1.0
 */
final class Activator {

	/**
	 * Non-autoloaded option holding the HMAC secret for signed order links.
	 *
	 * @var string
	 */
	public const TOKEN_SECRET_OPTION = 'pph_token_secret';

	/**
	 * Non-autoloaded option holding the installed schema version.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'pph_schema_version';

	/**
	 * Schema version before any migration has run.
	 *
	 * @var int
	 */
	public const INITIAL_SCHEMA_VERSION = 0;

	/**
	 * Raw byte length of the token secret.
	 *
	 * @var int
	 */
	private const SECRET_BYTES = 64;

	/**
	 * Hook of the single daily maintenance event.
	 *
	 * @var string
	 */
	public const CLEANUP_HOOK = 'pph_daily_cleanup';

	/**
	 * Runs on activation. Idempotent: re-activating changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::install_token_secret();

		add_option( self::SCHEMA_VERSION_OPTION, self::INITIAL_SCHEMA_VERSION, '', false );

		Schema::install();

		// A fresh install has the current shape by construction, so it records the
		// target version outright. Never lowered: a site that has been on a newer
		// build keeps its version, or its migrations would run a second time.
		if ( Migrator::installed_version() < Migrator::TARGET_VERSION ) {
			update_option( self::SCHEMA_VERSION_OPTION, Migrator::TARGET_VERSION, false );
		}

		self::schedule_cleanup();
	}

	/**
	 * Schedules the daily maintenance event if it is not already scheduled.
	 *
	 * One event, idempotent, bailing fast: it sweeps orphaned rows and expired
	 * rate-limit entries, and closed requests only where the merchant has set a
	 * retention window.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function schedule_cleanup(): void {
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}

		// Off the hour, so the sweep does not land with every other daily task.
		wp_schedule_event( time() + ( 17 * MINUTE_IN_SECONDS ), 'daily', self::CLEANUP_HOOK );
	}

	/**
	 * Generates the token secret once and never again.
	 *
	 * Regenerating it would invalidate every signed order link already sitting
	 * in a customer's inbox, so a plugin re-activation must not touch it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function install_token_secret(): void {
		if ( '' !== (string) get_option( self::TOKEN_SECRET_OPTION, '' ) ) {
			return;
		}

		add_option( self::TOKEN_SECRET_OPTION, self::generate_secret(), '', false );
	}

	/**
	 * Produces a base64-encoded 64-byte secret.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private static function generate_secret(): string {
		try {
			$bytes = random_bytes( self::SECRET_BYTES );
		} catch ( \Exception $e ) {
			// No CSPRNG available; WordPress's own generator is the documented fallback.
			$bytes = wp_generate_password( self::SECRET_BYTES, true, true );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding raw bytes for text storage, not obfuscation.
		return base64_encode( $bytes );
	}
}
