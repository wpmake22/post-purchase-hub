<?php
/**
 * Migration spy.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration\Migrations;

use PostPurchaseHub\Install\Migrations\Migration;

/**
 * Second spy, so ordering can be asserted rather than assumed.
 *
 * @since 0.2.0
 */
final class SpyMigrationTwo implements Migration {

	/**
	 * Times run.
	 *
	 * @var int
	 */
	public static int $runs = 0;

	/**
	 * Version produced.
	 *
	 * @return int
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * Applies the migration.
	 *
	 * @return void
	 */
	public function run(): void {
		++self::$runs;

		SpyMigrationOne::$order[] = 2;
	}
}
