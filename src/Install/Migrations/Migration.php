<?php
/**
 * Migration contract.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install\Migrations;

/**
 * One numbered, idempotent schema or data transform.
 *
 * Additive schema changes belong in Schema::install(), which dbDelta() applies
 * on every migration run. A class here is for the transforms dbDelta cannot
 * express, and each must be safe to run twice: the version option advances only
 * after a migration returns, so an interrupted upgrade re-runs the last one.
 *
 * Anything that could touch many rows must queue work for WP-CLI or the daily
 * cron sweep rather than doing it inline — an upgrade must not be the request
 * that times out.
 *
 * @since 0.2.0
 */
interface Migration {

	/**
	 * Schema version this migration brings the site up to.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function version(): int;

	/**
	 * Applies the migration.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function run(): void;
}
