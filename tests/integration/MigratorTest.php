<?php
/**
 * Migration runner integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Migrations\Migration;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Integration\Migrations\SpyMigrationOne;
use PostPurchaseHub\Tests\Integration\Migrations\SpyMigrationTwo;

/**
 * Covers the three things a migration runner has to get right: it runs pending
 * work in order, it records how far it got, and it does nothing at all when the
 * site is already current.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Install\Migrator
 */
final class MigratorTest extends \WP_UnitTestCase {

	/**
	 * Creates the tables once, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		Schema::install();
	}

	/**
	 * Resets the recorded version and the migration spies.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Activator::SCHEMA_VERSION_OPTION );

		SpyMigrationOne::reset();
	}

	/**
	 * A site already at the target version does no work.
	 *
	 * @return void
	 */
	public function test_a_current_site_runs_nothing(): void {
		update_option( Activator::SCHEMA_VERSION_OPTION, Migrator::TARGET_VERSION, false );

		$this->migrator()->maybe_migrate();

		$this->assertSame( 0, SpyMigrationOne::$runs );
		$this->assertSame( Migrator::TARGET_VERSION, Migrator::installed_version() );
	}

	/**
	 * A site ahead of this build is left alone rather than downgraded.
	 *
	 * @return void
	 */
	public function test_a_newer_site_is_left_alone(): void {
		update_option( Activator::SCHEMA_VERSION_OPTION, Migrator::TARGET_VERSION + 5, false );

		$this->migrator()->maybe_migrate();

		$this->assertSame( 0, SpyMigrationOne::$runs );
		$this->assertSame( Migrator::TARGET_VERSION + 5, Migrator::installed_version() );
	}

	/**
	 * A storefront request never alters the schema.
	 *
	 * @return void
	 */
	public function test_a_front_end_request_does_not_migrate(): void {
		update_option( Activator::SCHEMA_VERSION_OPTION, 0, false );

		$this->assertFalse( is_admin(), 'Test expects a non-admin context by default.' );

		$this->migrator()->maybe_migrate();

		$this->assertSame( 0, Migrator::installed_version() );
	}

	/**
	 * An admin request migrates and records the new version.
	 *
	 * @return void
	 */
	public function test_an_admin_request_migrates(): void {
		update_option( Activator::SCHEMA_VERSION_OPTION, 0, false );
		set_current_screen( 'dashboard' );

		$this->migrator()->maybe_migrate();

		set_current_screen( 'front' );

		$this->assertSame( Migrator::TARGET_VERSION, Migrator::installed_version() );
		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
	}

	/**
	 * Pending migrations run in ascending version order, once each.
	 *
	 * @return void
	 */
	public function test_migrations_run_in_order(): void {
		$this->migrator(
			array(
				2 => SpyMigrationTwo::class,
				1 => SpyMigrationOne::class,
			)
		)->migrate( 0 );

		$this->assertSame( array( 1, 2 ), SpyMigrationOne::$order );
		$this->assertSame( 1, SpyMigrationOne::$runs );
		$this->assertSame( 1, SpyMigrationTwo::$runs );
	}

	/**
	 * Migrations already applied are not run again.
	 *
	 * @return void
	 */
	public function test_applied_migrations_do_not_run_again(): void {
		$registry = array(
			1 => SpyMigrationOne::class,
			2 => SpyMigrationTwo::class,
		);

		$this->migrator( $registry )->migrate( 1 );

		$this->assertSame( 0, SpyMigrationOne::$runs );
		$this->assertSame( 1, SpyMigrationTwo::$runs );
	}

	/**
	 * Migrating twice from the same starting point is safe.
	 *
	 * @return void
	 */
	public function test_migrating_is_idempotent(): void {
		$registry = array( 1 => SpyMigrationOne::class );

		$migrator = $this->migrator( $registry );
		$migrator->migrate( 0 );

		$version = Migrator::installed_version();

		$migrator->migrate( Migrator::installed_version() );

		$this->assertSame( 1, SpyMigrationOne::$runs );
		$this->assertSame( $version, Migrator::installed_version() );
	}

	/**
	 * The version advances as each migration completes, so an interrupted
	 * upgrade resumes instead of starting over.
	 *
	 * @return void
	 */
	public function test_the_version_advances_per_migration(): void {
		$this->migrator( array( 1 => SpyMigrationOne::class ) )->migrate( 0 );

		$this->assertGreaterThanOrEqual( 1, Migrator::installed_version() );
	}

	/**
	 * The recorded version is never autoloaded on every request.
	 *
	 * @return void
	 */
	public function test_the_version_option_is_not_autoloaded(): void {
		$this->migrator()->migrate( 0 );

		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayNotHasKey( Activator::SCHEMA_VERSION_OPTION, wp_load_alloptions() );
	}

	/**
	 * Builds a migrator with an optional registry override.
	 *
	 * @param array<int, class-string<Migration>>|null $migrations Registry.
	 * @return Migrator
	 */
	private function migrator( ?array $migrations = null ): Migrator {
		return new Migrator( new Logger(), $migrations );
	}
}
