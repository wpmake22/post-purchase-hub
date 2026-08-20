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
 * Records that it ran, and the order both spies ran in.
 *
 * @since 0.2.0
 */
final class SpyMigrationOne implements Migration {

	/**
	 * Times run.
	 *
	 * @var int
	 */
	public static int $runs = 0;

	/**
	 * Versions applied across both spies, in order.
	 *
	 * @var array<int, int>
	 */
	public static array $order = array();

	/**
	 * Resets both spies.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$runs            = 0;
		self::$order           = array();
		SpyMigrationTwo::$runs = 0;
	}

	/**
	 * Version produced.
	 *
	 * @return int
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * Applies the migration.
	 *
	 * @return void
	 */
	public function run(): void {
		++self::$runs;
		self::$order[] = 1;
	}
}
