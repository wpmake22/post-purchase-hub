<?php
/**
 * WP-CLI timeline backfill command.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\CLI;

use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\TransitionRecorder;

/**
 * `wp pph backfill-timeline` — gives existing orders the dates we can defend.
 *
 * Never automatic, per docs/SPEC.md Phase 7: it touches every order in the
 * store, so it cannot run inside a request and cannot run without being asked.
 * Every pass is bounded, the position is stored, and interrupting it loses at
 * most the batch in flight — the same order processed twice is a no-op, because
 * the recorder is forward-only.
 *
 * Only `date_created`, `date_paid` and `date_completed` are read. Those are the
 * only per-order moments WooCommerce persists; anything else on a timeline for
 * a historical order would be fiction.
 *
 * @since 0.3.0
 */
final class BackfillCommand {

	/**
	 * Non-autoloaded option holding the resume position.
	 *
	 * @var string
	 */
	public const CURSOR_OPTION = 'pph_backfill_cursor';

	/**
	 * Orders loaded per batch.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Transition recorder.
	 *
	 * @var TransitionRecorder
	 */
	private TransitionRecorder $recorder;

	/**
	 * Stage definitions.
	 *
	 * @var StageMap
	 */
	private StageMap $stages;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param TransitionRecorder $recorder Transition recorder.
	 * @param StageMap           $stages   Stage definitions.
	 */
	public function __construct( TransitionRecorder $recorder, StageMap $stages ) {
		$this->recorder = $recorder;
		$this->stages   = $stages;
	}

	/**
	 * Derives timeline entries for orders placed before the plugin was recording.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Orders loaded per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--limit=<number>]
	 * : Stop after this many orders. Omit to run to the end of the store.
	 *
	 * [--dry-run]
	 * : Report what would be written without writing it.
	 *
	 * [--reset]
	 * : Start from the first order again instead of resuming.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pph backfill-timeline --dry-run
	 *     wp pph backfill-timeline --limit=5000
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! function_exists( 'wc_get_orders' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$batch   = max( 1, (int) ( $assoc_args['batch-size'] ?? self::BATCH_SIZE ) );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : PHP_INT_MAX;

		if ( isset( $assoc_args['reset'] ) ) {
			delete_option( self::CURSOR_OPTION );
		}

		$cursor = $this->cursor();

		\WP_CLI::log( sprintf( 'Resuming at page %d (last order #%d).', $cursor['page'], $cursor['last_id'] ) );

		$result = $this->run( $cursor, $batch, $limit, $dry_run );

		\WP_CLI::log( sprintf( 'Orders scanned:  %d', $result['scanned'] ) );
		\WP_CLI::log( sprintf( 'Orders updated:  %d', $result['updated'] ) );
		\WP_CLI::log( sprintf( 'Entries written: %d', $result['entries'] ) );

		if ( $result['remaining'] ) {
			\WP_CLI::success( 'Stopped at the limit. Run the command again to continue.' );
			return;
		}

		if ( ! $dry_run ) {
			delete_option( self::CURSOR_OPTION );
		}

		\WP_CLI::success( $dry_run ? 'Dry run complete; nothing was written.' : 'Backfill complete.' );
	}

	/**
	 * Walks the store from the cursor, backfilling as it goes.
	 *
	 * @since 0.3.0
	 *
	 * @param array{page: int, last_id: int} $cursor  Resume position.
	 * @param int                            $batch   Orders per batch.
	 * @param int                            $limit   Most orders to scan.
	 * @param bool                           $dry_run Whether to write.
	 * @return array{scanned: int, updated: int, entries: int, remaining: bool}
	 */
	private function run( array $cursor, int $batch, int $limit, bool $dry_run ): array {
		$scanned = 0;
		$updated = 0;
		$entries = 0;
		$page    = $cursor['page'];
		$last_id = $cursor['last_id'];
		$more    = true;

		while ( $more && $scanned < $limit ) {
			$orders = wc_get_orders(
				array(
					'limit'   => $batch,
					'page'    => $page,
					'type'    => 'shop_order',
					'status'  => 'any',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			$orders = is_array( $orders ) ? $orders : array();
			$more   = count( $orders ) === $batch;

			$stopped = false;

			foreach ( $orders as $order ) {
				// The id guard, not the page, is what makes a resume exact: a page
				// half-finished last run is re-read and its handled orders skipped.
				if ( ! $order instanceof \WC_Order || $order->get_id() <= $last_id ) {
					continue;
				}

				if ( $scanned >= $limit ) {
					$stopped = true;
					$more    = true;
					break;
				}

				++$scanned;
				$last_id = $order->get_id();

				$written = $this->backfill( $order, $dry_run );

				if ( $written > 0 ) {
					++$updated;
					$entries += $written;
				}
			}

			if ( ! $stopped ) {
				++$page;
			}

			if ( ! $dry_run ) {
				$this->save_cursor( $page, $last_id );
			}

			if ( $stopped ) {
				break;
			}
		}

		return array(
			'scanned'   => $scanned,
			'updated'   => $updated,
			'entries'   => $entries,
			'remaining' => $more,
		);
	}

	/**
	 * Backfills one order, in a single save.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order   Order to backfill.
	 * @param bool      $dry_run Whether to write.
	 * @return int Entries added.
	 */
	private function backfill( \WC_Order $order, bool $dry_run ): int {
		$added = 0;

		foreach ( $this->derived( $order ) as $status => $timestamp ) {
			if ( $this->recorder->append( $order, (string) $status, $timestamp ) ) {
				++$added;
			}
		}

		if ( $added > 0 && ! $dry_run ) {
			$order->save();
		}

		return $added;
	}

	/**
	 * The transitions an order's stored dates support, oldest first.
	 *
	 * `date_paid` is attributed to `processing` and `date_completed` to
	 * `completed`, which is what WooCommerce's own payment_complete() and
	 * status handlers set them from. The attribution is an inference on an order
	 * that went straight from pending to completed — it will show a confirmed
	 * stage that was never separately occupied — and that is the honest limit of
	 * what can be recovered from a store that was never recording.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array<string, string> Status slug => UTC `Y-m-d H:i:s`.
	 */
	private function derived( \WC_Order $order ): array {
		$dates = array(
			'pending'    => $order->get_date_created(),
			'processing' => $order->get_date_paid(),
			'completed'  => $order->get_date_completed(),
		);

		$derived = array();

		foreach ( $dates as $status => $date ) {
			if ( ! $date instanceof \WC_DateTime || null === $this->stages->stage_for_status( $status ) ) {
				continue;
			}

			// Clone: WC_DateTime is mutable and this one belongs to the order.
			$derived[ $status ] = ( clone $date )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}

		return $derived;
	}

	/**
	 * The stored resume position.
	 *
	 * Pagination plus a high-water order id, rather than an id range, because
	 * `wc_get_orders()` has no portable id-range filter and the query has to
	 * behave the same on HPOS and on posts storage.
	 *
	 * @since 0.3.0
	 *
	 * @return array{page: int, last_id: int}
	 */
	private function cursor(): array {
		$stored = get_option( self::CURSOR_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'page'    => max( 1, (int) ( $stored['page'] ?? 1 ) ),
			'last_id' => max( 0, (int) ( $stored['last_id'] ?? 0 ) ),
		);
	}

	/**
	 * Stores the resume position.
	 *
	 * @since 0.3.0
	 *
	 * @param int $page    Next page to read.
	 * @param int $last_id Highest order id handled.
	 * @return void
	 */
	private function save_cursor( int $page, int $last_id ): void {
		update_option(
			self::CURSOR_OPTION,
			array(
				'page'    => $page,
				'last_id' => $last_id,
			),
			false
		);
	}
}
