<?php
/**
 * Retention sweep for request data.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Support\Logger;

/**
 * Removes what a store no longer needs: closed requests past their retention
 * window, item rows whose request is gone, and expired rate-limit entries.
 *
 * Retention is **off by default**. Deleting a merchant's cancellation history
 * without being asked is not a default worth having, and at the volumes in
 * docs/SPEC.md Phase 7 the table stays small on its own. Setting a positive
 * number of days opts in.
 *
 * Only terminal statuses are ever swept, so a request still waiting on a
 * merchant cannot age out from under them, and every pass is bounded: the sweep
 * runs a fixed number of batches, then stops and leaves the rest for next time.
 *
 * @since 0.2.0
 */
final class RetentionSweeper {

	/**
	 * Settings key holding the retention window, in days.
	 *
	 * @var string
	 */
	public const RETENTION_SETTING = 'request_retention_days';

	/**
	 * Days of history kept when the merchant has not chosen.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 0;

	/**
	 * Ceiling on the configured window, so a typo cannot mean "never".
	 *
	 * @var int
	 */
	public const MAX_RETENTION_DAYS = 3650;

	/**
	 * Rows removed per batch.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 200;

	/**
	 * Batches the daily event sweeps before leaving the rest for tomorrow.
	 *
	 * @var int
	 */
	public const CRON_BATCHES = 10;

	/**
	 * Statuses that may be swept once past the window.
	 *
	 * Listed rather than derived as "not pending" so a status added by an
	 * extension is kept by default: forgetting to sweep is recoverable, and
	 * deleting someone else's open request is not.
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATUSES = array(
		Request::STATUS_APPROVED,
		Request::STATUS_DECLINED,
		Request::STATUS_WITHDRAWN,
		Request::STATUS_COMPLETED,
	);

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Runs one bounded sweep.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $batches Maximum batches per task.
	 * @param bool $dry_run When true, counts without deleting.
	 * @return array{requests: int, orphaned_items: int, expired_transients: int, retention_days: int, dry_run: bool}
	 */
	public function sweep( int $batches = 1, bool $dry_run = false ): array {
		$batches = max( 1, $batches );
		$days    = $this->retention_days();

		$result = array(
			'requests'           => $days > 0 ? $this->sweep_requests( $days, $batches, $dry_run ) : 0,
			'orphaned_items'     => $this->sweep_orphaned_items( $batches, $dry_run ),
			'expired_transients' => $this->sweep_expired_transients( $dry_run ),
			'retention_days'     => $days,
			'dry_run'            => $dry_run,
		);

		if ( ! $dry_run && ( $result['requests'] || $result['orphaned_items'] ) ) {
			$this->logger->info( 'Retention sweep removed request data.', $result );
		}

		return $result;
	}

	/**
	 * The configured retention window, in days. Zero means keep everything.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function retention_days(): int {
		$settings = get_option( 'pph_settings', array() );
		$days     = is_array( $settings ) ? ( $settings[ self::RETENTION_SETTING ] ?? self::DEFAULT_RETENTION_DAYS ) : self::DEFAULT_RETENTION_DAYS;

		/**
		 * Filters how many days of closed requests are kept.
		 *
		 * Zero disables the sweep entirely.
		 *
		 * @since 0.2.0
		 *
		 * @param int $days Retention window in days.
		 */
		$days = (int) apply_filters( 'pph_request_retention_days', (int) $days );

		return min( max( 0, $days ), self::MAX_RETENTION_DAYS );
	}

	/**
	 * Deletes closed requests older than the window, with their item rows.
	 *
	 * @since 0.2.0
	 * @param int  $days    Retention window.
	 * @param int  $batches Maximum batches.
	 * @param bool $dry_run When true, counts without deleting.
	 * @return int Rows removed, or that would be removed.
	 */
	private function sweep_requests( int $days, int $batches, bool $dry_run ): int {
		global $wpdb;

		$cutoff       = gmdate( RequestQuery::DATE_FORMAT, time() - ( $days * DAY_IN_SECONDS ) );
		$placeholders = implode( ', ', array_fill( 0, count( self::TERMINAL_STATUSES ), '%s' ) );
		$removed      = 0;

		for ( $batch = 0; $batch < $batches; $batch++ ) {
			$sql = sprintf(
				'SELECT id FROM %s WHERE status IN (%s) AND created_at < %%s ORDER BY created_at ASC LIMIT %%d',
				Schema::requests_table(),
				$placeholders
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; identifiers are class constants and every value is a placeholder argument.
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( self::TERMINAL_STATUSES, array( $cutoff, self::BATCH_SIZE ) ) ) );
			$ids = array_values( array_map( 'intval', (array) $ids ) );

			if ( ! $ids ) {
				break;
			}

			$removed += count( $ids );

			if ( $dry_run ) {
				break;
			}

			// Items first: the cascade is ours to run, so an interruption must not orphan rows.
			$this->delete_by_ids( Schema::request_items_table(), 'request_id', $ids );
			$this->delete_by_ids( Schema::requests_table(), 'id', $ids );
		}

		return $removed;
	}

	/**
	 * Deletes item rows whose request no longer exists.
	 *
	 * @since 0.2.0
	 * @param int  $batches Maximum batches.
	 * @param bool $dry_run When true, counts without deleting.
	 * @return int Rows removed, or that would be removed.
	 */
	private function sweep_orphaned_items( int $batches, bool $dry_run ): int {
		global $wpdb;

		$removed = 0;

		for ( $batch = 0; $batch < $batches; $batch++ ) {
			$sql = sprintf(
				'SELECT i.id FROM %s i LEFT JOIN %s r ON i.request_id = r.id WHERE r.id IS NULL LIMIT %%d',
				Schema::request_items_table(),
				Schema::requests_table()
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables; identifiers come from Schema and the limit is a placeholder argument.
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, self::BATCH_SIZE ) );
			$ids = array_values( array_map( 'intval', (array) $ids ) );

			if ( ! $ids ) {
				break;
			}

			$removed += count( $ids );

			if ( $dry_run ) {
				break;
			}

			$this->delete_by_ids( Schema::request_items_table(), 'id', $ids );
		}

		return $removed;
	}

	/**
	 * Deletes this plugin's expired transients.
	 *
	 * WordPress sweeps expired transients daily too, but doing our own prefix
	 * keeps rate-limit rows from piling up between core's passes.
	 *
	 * @since 0.2.0
	 * @param bool $dry_run When true, counts without deleting.
	 * @return int
	 */
	private function sweep_expired_transients( bool $dry_run ): int {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients cannot be enumerated through the options API.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_pph_' ) . '%',
				time(),
				self::BATCH_SIZE
			)
		);

		if ( $dry_run ) {
			return count( (array) $names );
		}

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_timeout_' ) ) );
		}

		return count( (array) $names );
	}

	/**
	 * Deletes rows by a list of integer ids.
	 *
	 * @since 0.2.0
	 * @param string $table  Fully prefixed table name.
	 * @param string $column Integer column to match.
	 * @param array  $ids    Ids to remove.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return void
	 */
	private function delete_by_ids( string $table, string $column, array $ids ): void {
		global $wpdb;

		$sql = sprintf(
			'DELETE FROM %s WHERE %s IN (%s)',
			$table,
			$column,
			implode( ', ', array_fill( 0, count( $ids ), '%d' ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables; the table and column are internal constants and every id is a placeholder argument.
		$wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
	}
}
