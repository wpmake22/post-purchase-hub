<?php
/**
 * WP-CLI cleanup command.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\CLI;

use PostPurchaseHub\Install\Uninstaller;
use PostPurchaseHub\Requests\RetentionSweeper;

/**
 * `wp pph cleanup` — the same sweep the daily event runs, on demand.
 *
 * Exists because the first thing support asks a merchant with a large store to
 * do is run the maintenance task by hand, with output they can paste back.
 * Every mode is idempotent and bounded, and `--dry-run` reports without writing.
 *
 * @since 0.2.0
 */
final class CleanupCommand {

	/**
	 * Retention sweeper.
	 *
	 * @var RetentionSweeper
	 */
	private RetentionSweeper $sweeper;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param RetentionSweeper $sweeper Retention sweeper.
	 */
	public function __construct( RetentionSweeper $sweeper ) {
		$this->sweeper = $sweeper;
	}

	/**
	 * Removes expired request data.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<number>]
	 * : How many batches to sweep. Each batch handles up to 200 rows.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be removed without removing it.
	 *
	 * [--order-meta]
	 * : Also strip `_pph_*` meta from orders. Intended for finishing an uninstall
	 * on a store too large to complete in one request. Destructive: it removes
	 * timeline and estimated-delivery data from live orders.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt for --order-meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pph cleanup --dry-run
	 *     wp pph cleanup --batches=50
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = isset( $assoc_args['dry-run'] );
		$batches = max( 1, (int) ( $assoc_args['batches'] ?? RetentionSweeper::CRON_BATCHES ) );

		$result = $this->sweeper->sweep( $batches, $dry_run );

		\WP_CLI::log(
			sprintf(
				'Retention window: %s',
				$result['retention_days'] > 0
					? sprintf( '%d days', $result['retention_days'] )
					: 'off (closed requests are kept indefinitely)'
			)
		);

		\WP_CLI::log( sprintf( 'Closed requests:    %d', $result['requests'] ) );
		\WP_CLI::log( sprintf( 'Orphaned items:     %d', $result['orphaned_items'] ) );
		\WP_CLI::log( sprintf( 'Expired transients: %d', $result['expired_transients'] ) );

		if ( isset( $assoc_args['order-meta'] ) ) {
			$this->sweep_order_meta( $dry_run, isset( $assoc_args['yes'] ) );
		}

		\WP_CLI::success( $dry_run ? 'Dry run complete; nothing was removed.' : 'Cleanup complete.' );
	}

	/**
	 * Strips this plugin's order meta.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $dry_run   When true, reports without writing.
	 * @param bool $confirmed Whether --yes was passed.
	 * @return void
	 */
	private function sweep_order_meta( bool $dry_run, bool $confirmed ): void {
		if ( $dry_run ) {
			\WP_CLI::log( 'Order meta:         skipped (--dry-run cannot scan without loading orders).' );
			return;
		}

		if ( ! $confirmed ) {
			\WP_CLI::confirm( 'Remove _pph_* meta from every order? Timeline history cannot be recovered.' );
		}

		$result = Uninstaller::delete_order_meta( 100, PHP_INT_MAX, 300 );

		\WP_CLI::log( sprintf( 'Orders scanned:     %d', $result['orders_scanned'] ) );
		\WP_CLI::log( sprintf( 'Orders cleaned:     %d', $result['orders_cleaned'] ) );

		if ( $result['remaining'] ) {
			\WP_CLI::warning( 'Time budget reached before the last order. Run the command again to continue.' );
		}
	}
}
