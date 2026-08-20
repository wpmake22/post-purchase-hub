<?php
/**
 * Activation and deactivation integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Deactivator;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Support\Cache;

/**
 * Covers the install lifecycle: what activation creates, what re-activation must
 * leave alone, and what deactivation is allowed to remove.
 *
 * @since 0.1.0
 *
 * @covers \PostPurchaseHub\Install\Activator
 * @covers \PostPurchaseHub\Install\Deactivator
 */
final class ActivationTest extends \WP_UnitTestCase {

	/**
	 * Starts each test from an uninstalled state.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Activator::TOKEN_SECRET_OPTION );
		delete_option( Activator::SCHEMA_VERSION_OPTION );
		delete_option( Cache::GENERATION_OPTION );
	}

	/**
	 * Activation creates a 64-byte secret and the schema-version placeholder.
	 *
	 * @return void
	 */
	public function test_activation_creates_the_token_secret_and_schema_version(): void {
		Activator::activate();

		$secret = (string) get_option( Activator::TOKEN_SECRET_OPTION );

		$this->assertNotSame( '', $secret );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Asserting the stored secret decodes to 64 raw bytes.
		$this->assertSame( 64, strlen( (string) base64_decode( $secret, true ) ) );
		$this->assertSame( Migrator::TARGET_VERSION, (int) get_option( Activator::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Activation creates both tables.
	 *
	 * @return void
	 */
	public function test_activation_creates_the_tables(): void {
		Activator::activate();

		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
		$this->assertTrue( Schema::table_exists( Schema::request_items_table() ) );
	}

	/**
	 * Activation schedules the one daily maintenance event.
	 *
	 * @return void
	 */
	public function test_activation_schedules_the_daily_sweep(): void {
		wp_clear_scheduled_hook( Activator::CLEANUP_HOOK );

		Activator::activate();

		$scheduled = wp_next_scheduled( Activator::CLEANUP_HOOK );

		$this->assertNotFalse( $scheduled );
		$this->assertSame( 'daily', wp_get_schedule( Activator::CLEANUP_HOOK ) );

		// Re-activating must not stack a second event on the same hook.
		Activator::activate();

		$this->assertSame( $scheduled, wp_next_scheduled( Activator::CLEANUP_HOOK ) );
	}

	/**
	 * Neither install option is autoloaded on every request.
	 *
	 * @return void
	 */
	public function test_the_install_options_are_not_autoloaded(): void {
		Activator::activate();

		wp_cache_delete( 'alloptions', 'options' );
		$autoloaded = wp_load_alloptions();

		$this->assertArrayNotHasKey( Activator::TOKEN_SECRET_OPTION, $autoloaded );
		$this->assertArrayNotHasKey( Activator::SCHEMA_VERSION_OPTION, $autoloaded );
	}

	/**
	 * Re-activation keeps the secret, because rotating it would kill every
	 * signed order link already sitting in a customer's inbox.
	 *
	 * @return void
	 */
	public function test_reactivation_preserves_the_token_secret(): void {
		Activator::activate();
		$first = (string) get_option( Activator::TOKEN_SECRET_OPTION );

		Activator::activate();

		$this->assertSame( $first, (string) get_option( Activator::TOKEN_SECRET_OPTION ) );
	}

	/**
	 * Re-activation does not reset a schema version a migration has advanced.
	 *
	 * @return void
	 */
	public function test_reactivation_preserves_an_advanced_schema_version(): void {
		Activator::activate();
		update_option( Activator::SCHEMA_VERSION_OPTION, Migrator::TARGET_VERSION + 6, false );

		Activator::activate();

		$this->assertSame( Migrator::TARGET_VERSION + 6, (int) get_option( Activator::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Deactivation clears scheduled work.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_scheduled_events(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'pph_daily_cleanup' );

		$this->assertNotFalse( wp_next_scheduled( 'pph_daily_cleanup' ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'pph_daily_cleanup' ) );
	}

	/**
	 * Deactivation invalidates cached values and removes their rows.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_cached_values(): void {
		global $wpdb;

		$cache = new Cache();
		$cache->set( 'used-statuses', array( 'wc-completed' ), HOUR_IN_SECONDS );
		$cache->incr( 'ip:198.51.100.4', 900 );

		Deactivator::deactivate();

		$this->assertNull( ( new Cache() )->get( 'used-statuses' ) );

		if ( ! wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting on the rows themselves.
			$rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_pph_' ) . '%'
				)
			);

			$this->assertSame( 0, $rows );
		}
	}

	/**
	 * Deactivation is not uninstallation: every option survives it.
	 *
	 * @return void
	 */
	public function test_deactivation_keeps_the_plugin_options(): void {
		Activator::activate();
		$secret = (string) get_option( Activator::TOKEN_SECRET_OPTION );

		Deactivator::deactivate();

		$this->assertSame( $secret, (string) get_option( Activator::TOKEN_SECRET_OPTION ) );
		$this->assertSame( Migrator::TARGET_VERSION, (int) get_option( Activator::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Deactivating twice is harmless.
	 *
	 * @return void
	 */
	public function test_deactivation_is_idempotent(): void {
		Activator::activate();

		Deactivator::deactivate();
		Deactivator::deactivate();

		$this->assertNotSame( '', (string) get_option( Activator::TOKEN_SECRET_OPTION ) );
	}
}
