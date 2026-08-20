<?php
/**
 * Activation routine.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

/**
 * Prepares the options the plugin needs before it can do anything.
 *
 * Runs in a request where the plugin was loaded but not bootstrapped, so it
 * takes no services and touches nothing beyond its own options.
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
	 * Runs on activation. Idempotent: re-activating changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::install_token_secret();

		add_option( self::SCHEMA_VERSION_OPTION, self::INITIAL_SCHEMA_VERSION, '', false );
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
