<?php
/**
 * Schema version tracking and migration runner.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

use PostPurchaseHub\Install\Migrations\Migration;
use PostPurchaseHub\Support\Logger;

/**
 * Brings a site's schema up to the version this build expects.
 *
 * The check costs one non-autoloaded option read per request and returns
 * immediately when the versions match, which is the common case for the life of
 * an install.
 *
 * @since 0.2.0
 */
final class Migrator {

	/**
	 * Schema version this build expects.
	 *
	 * @var int
	 */
	public const TARGET_VERSION = 1;

	/**
	 * Migrations keyed by the version they produce.
	 *
	 * An explicit registry rather than a directory scan: a class file appearing
	 * in Migrations/ should not become executable schema work by virtue of
	 * existing, and the intended order has to be readable in one place.
	 *
	 * @var array<int, class-string<Migration>>
	 */
	private const MIGRATIONS = array();

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Migrations keyed by target version.
	 *
	 * @var array<int, class-string<Migration>>
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param Logger                                   $logger     Logger.
	 * @param array<int, class-string<Migration>>|null $migrations Registry override, for tests.
	 */
	public function __construct( Logger $logger, ?array $migrations = null ) {
		$this->logger     = $logger;
		$this->migrations = $migrations ?? self::MIGRATIONS;
	}

	/**
	 * Installed schema version, 0 on a site that has never installed.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public static function installed_version(): int {
		return (int) get_option( Activator::SCHEMA_VERSION_OPTION, 0 );
	}

	/**
	 * Runs migrations when the installed version is behind this build.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$installed = self::installed_version();

		if ( $installed >= self::TARGET_VERSION ) {
			return;
		}

		if ( ! self::context_allows_migration() ) {
			return;
		}

		$this->migrate( $installed );
	}

	/**
	 * Applies the schema and every pending migration, in version order.
	 *
	 * The version option advances after each migration, so an interrupted run
	 * resumes rather than restarting.
	 *
	 * @since 0.2.0
	 *
	 * @param int $from Version to migrate from.
	 * @return void
	 */
	public function migrate( int $from ): void {
		Schema::install();

		foreach ( $this->pending( $from ) as $version => $class ) {
			$migration = new $class();

			if ( ! $migration instanceof Migration ) {
				$this->logger->error( 'Registered migration does not implement the Migration interface.', array( 'class' => $class ) );
				return;
			}

			if ( $migration->version() !== $version ) {
				// A mis-keyed registry would record a version the site has not reached.
				$this->logger->error(
					'Migration is registered under a version it does not declare.',
					array(
						'class'      => $class,
						'registered' => $version,
						'declared'   => $migration->version(),
					)
				);
				return;
			}

			$migration->run();

			$this->store_version( $version );

			$this->logger->info( 'Applied schema migration.', array( 'version' => $version ) );
		}

		$this->store_version( self::TARGET_VERSION );
	}

	/**
	 * Migrations newer than the given version, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @param int $from Version to migrate from.
	 * @return array<int, class-string<Migration>>
	 */
	private function pending( int $from ): array {
		$pending = array_filter(
			$this->migrations,
			static function ( int $version ) use ( $from ): bool {
				return $version > $from && $version <= self::TARGET_VERSION;
			},
			ARRAY_FILTER_USE_KEY
		);

		ksort( $pending );

		return $pending;
	}

	/**
	 * Records the installed schema version.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version Version to store.
	 * @return void
	 */
	private function store_version( int $version ): void {
		update_option( Activator::SCHEMA_VERSION_OPTION, $version, false );
	}

	/**
	 * Whether this request is an acceptable place to alter the schema.
	 *
	 * Schema changes belong in an administrative context, not in a customer's
	 * page view: a storefront request should never be the one paying for an
	 * ALTER, and never the one that fails half way through it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	private static function context_allows_migration(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}
}
