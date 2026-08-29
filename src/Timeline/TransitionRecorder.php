<?php
/**
 * Forward-only recorder of order status transitions.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

use PostPurchaseHub\Support\Logger;

/**
 * Writes the transition history WooCommerce does not keep.
 *
 * Core persists `date_created`, `date_paid`, `date_completed` and
 * `date_modified` and nothing else, so a timeline with real dates on it has to
 * be recorded as the order moves. The obvious shortcut — reading it back out of
 * order notes — is not available: notes are translated at write time and
 * merchants edit and delete them, so they are prose, not data.
 *
 * Two invariants make the stored array safe to grow with activity, per hard
 * rule 12. It is **forward-only**: the first time a stage is reached is the
 * timestamp that stands, and a status returning to a stage never rewrites it.
 * And it is **capped**: overflow drops the oldest progress entry, never a
 * branch state, because "when was this cancelled" outlives "when was this
 * packed".
 *
 * @since 0.3.0
 */
final class TransitionRecorder {

	/**
	 * Order meta key holding the recorded transitions.
	 *
	 * @var string
	 */
	public const META_KEY = '_wpmphub_timeline';

	/**
	 * Most entries one order may hold.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 10;

	/**
	 * Stage definitions.
	 *
	 * @var StageMap
	 */
	private StageMap $stages;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param StageMap $stages Stage definitions.
	 * @param Logger   $logger Logger.
	 */
	public function __construct( StageMap $stages, Logger $logger ) {
		$this->stages = $stages;
		$this->logger = $logger;
	}

	/**
	 * Records a transition and saves the order.
	 *
	 * Callback for `woocommerce_order_status_changed`, which fires from inside
	 * WC_Order::save() after the write has completed and after the transition
	 * flag has been cleared — so saving again here appends the meta without
	 * re-entering the transition, and costs one write rather than a reload.
	 *
	 * Core catches anything thrown from this hook and turns it into an order
	 * note the merchant has to read, so nothing here is allowed to escape.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved away from.
	 * @param string $to       Status moved to.
	 * @param mixed  $order    Order object as passed by WooCommerce.
	 * @return void
	 */
	public function record( int $order_id, string $from, string $to, $order = null ): void {
		unset( $from );

		try {
			if ( ! $order instanceof \WC_Order ) {
				$order = wc_get_order( $order_id );
			}

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			if ( $this->append( $order, $to, self::now() ) ) {
				$order->save();
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'Could not record a status transition.',
				array(
					'order_id' => $order_id,
					'status'   => $to,
					'error'    => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Adds one entry to an order's timeline meta without saving.
	 *
	 * Left unsaved so a caller writing several entries — the backfill command
	 * does exactly that — pays for one save rather than one per entry.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order         Order to record against.
	 * @param string    $status        Status reached, prefixed or not.
	 * @param string    $timestamp_utc Moment it was reached, UTC `Y-m-d H:i:s`.
	 * @return bool Whether the order's meta changed.
	 */
	public function append( \WC_Order $order, string $status, string $timestamp_utc ): bool {
		$status = StageMap::normalise_status( $status );
		$stage  = $this->stages->stage_for_status( $status );

		if ( null === $stage || '' === $timestamp_utc ) {
			return false;
		}

		$entries = $this->read( $order );

		foreach ( $entries as $entry ) {
			// Forward-only: the first arrival at a stage is the one that stands.
			if ( $entry['stage'] === $stage ) {
				return false;
			}
		}

		$entries[] = array(
			'status'        => $status,
			'stage'         => $stage,
			'timestamp_utc' => $timestamp_utc,
		);

		$order->update_meta_data( self::META_KEY, $this->trim( $this->sort( $entries ) ) );

		return true;
	}

	/**
	 * Reads an order's recorded transitions, discarding anything malformed.
	 *
	 * Meta can be corrupted by an import, a migration or another plugin, and a
	 * customer's order page failing over it would be a far worse outcome than a
	 * timeline missing its dates. Bad data is logged and dropped.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return list<array{status: string, stage: string, timestamp_utc: string}>
	 */
	public function read( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY, true );

		if ( '' === $raw || array() === $raw || null === $raw ) {
			return array();
		}

		if ( ! is_array( $raw ) ) {
			$this->logger->warning(
				'Discarded timeline meta that was not an array.',
				array(
					'order_id' => $order->get_id(),
					'type'     => get_debug_type( $raw ),
				)
			);

			return array();
		}

		$entries = array();
		$dropped = 0;

		foreach ( $raw as $entry ) {
			$clean = self::clean_entry( $entry );

			if ( null === $clean ) {
				++$dropped;
				continue;
			}

			$entries[] = $clean;
		}

		if ( $dropped > 0 ) {
			$this->logger->warning(
				'Discarded malformed timeline entries.',
				array(
					'order_id' => $order->get_id(),
					'dropped'  => $dropped,
				)
			);
		}

		return $this->sort( $entries );
	}

	/**
	 * The current moment, formatted the way entries store it.
	 *
	 * @since 0.3.0
	 *
	 * @return string UTC `Y-m-d H:i:s`.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Validates one stored entry.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $entry Raw entry.
	 * @return array{status: string, stage: string, timestamp_utc: string}|null
	 */
	private static function clean_entry( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$status    = $entry['status'] ?? null;
		$stage     = $entry['stage'] ?? null;
		$timestamp = $entry['timestamp_utc'] ?? null;

		if ( ! is_string( $stage ) || '' === $stage || ! is_string( $timestamp ) || '' === $timestamp ) {
			return null;
		}

		return array(
			'status'        => is_string( $status ) ? $status : '',
			'stage'         => $stage,
			'timestamp_utc' => $timestamp,
		);
	}

	/**
	 * Orders entries oldest first.
	 *
	 * Entries recorded live arrive in order already; the backfill can add an
	 * older one to an order that has newer entries, so the invariant is restored
	 * here rather than assumed.
	 *
	 * @since 0.3.0
	 *
	 * @param list<array{status: string, stage: string, timestamp_utc: string}> $entries Entries.
	 * @return list<array{status: string, stage: string, timestamp_utc: string}>
	 */
	private function sort( array $entries ): array {
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return strcmp( $a['timestamp_utc'], $b['timestamp_utc'] );
			}
		);

		return $entries;
	}

	/**
	 * Enforces the entry cap, sacrificing progress stages before branch states.
	 *
	 * @since 0.3.0
	 *
	 * @param list<array{status: string, stage: string, timestamp_utc: string}> $entries Entries.
	 * @return list<array{status: string, stage: string, timestamp_utc: string}>
	 */
	private function trim( array $entries ): array {
		$total = count( $entries );

		while ( $total > self::MAX_ENTRIES ) {
			$victim = null;

			foreach ( $entries as $index => $entry ) {
				if ( ! $this->stages->is_branch( $entry['stage'] ) ) {
					$victim = $index;
					break;
				}
			}

			// Every remaining entry is a branch state: drop the oldest of those.
			unset( $entries[ $victim ?? 0 ] );

			$entries = array_values( $entries );
			--$total;
		}

		return $entries;
	}
}
