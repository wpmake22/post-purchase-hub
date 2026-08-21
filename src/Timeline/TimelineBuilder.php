<?php
/**
 * Builds the timeline view model for one order.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * Turns an order plus its recorded transitions into something a template can echo.
 *
 * Reads only what is already loaded on the order object, so building a timeline
 * costs no queries — the orders list in M04 renders one of these per row and
 * must add none.
 *
 * Two things are deliberately not done here. Nothing is derived from
 * `date_paid` or `date_completed`: that inference belongs to the opt-in backfill
 * command, where a merchant has asked for it, not to a read path that would
 * quietly present a guess as a record. And nothing is written — hard rule 13,
 * and a customer refreshing an order page must not mutate it.
 *
 * @since 0.3.0
 */
final class TimelineBuilder {

	/**
	 * Stage definitions.
	 *
	 * @var StageMap
	 */
	private StageMap $stages;

	/**
	 * Recorder, used for its reader and its meta key.
	 *
	 * @var TransitionRecorder
	 */
	private TransitionRecorder $recorder;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param StageMap           $stages   Stage definitions.
	 * @param TransitionRecorder $recorder Transition reader, which also owns malformed-meta handling.
	 */
	public function __construct( StageMap $stages, TransitionRecorder $recorder ) {
		$this->stages   = $stages;
		$this->recorder = $recorder;
	}

	/**
	 * Builds the timeline for an order.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return Timeline
	 */
	public function build( \WC_Order $order ): Timeline {
		$status  = StageMap::normalise_status( $order->get_status() );
		$current = $this->stages->stage_for_status( $status );
		$records = $this->records( $order );

		$branch_key = ( null !== $current && $this->stages->is_branch( $current ) ) ? $current : null;
		$reached    = $this->reached( $records, null === $branch_key ? $current : null );

		$stages = array();

		foreach ( $this->stages->stages() as $key => $label ) {
			$position = $this->stages->position( $key );
			$record   = $records[ $key ] ?? null;

			$stages[] = new TimelineStage(
				$key,
				$label,
				$this->state( $position, $reached, null !== $branch_key ),
				$record['timestamp_utc'] ?? null,
				isset( $record['status'] ) && '' !== $record['status'] ? $record['status'] : null
			);
		}

		$branch = null;

		if ( null !== $branch_key ) {
			$record = $records[ $branch_key ] ?? null;

			$branch = new TimelineStage(
				$branch_key,
				$this->stages->label( $branch_key ),
				TimelineStage::STATE_CURRENT,
				$record['timestamp_utc'] ?? null,
				$status
			);
		}

		return new Timeline(
			$order->get_id(),
			$status,
			$stages,
			$branch,
			$this->is_historical( $stages, $branch, $current )
		);
	}

	/**
	 * The order's recorded transitions, keyed by stage, plus what we know without them.
	 *
	 * "Placed" is filled from `date_created` because that is a stored property of
	 * every order rather than a reconstruction: WooCommerce never fires
	 * `woocommerce_order_status_changed` for the transition into an order's first
	 * status, so without this even an order created a minute ago would show no
	 * date at all.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array<string, array{status: string, stage: string, timestamp_utc: string}>
	 */
	private function records( \WC_Order $order ): array {
		$records = array();

		foreach ( $this->recorder->read( $order ) as $entry ) {
			// Forward-only on read too: an older duplicate wins over a newer one.
			if ( ! isset( $records[ $entry['stage'] ] ) ) {
				$records[ $entry['stage'] ] = $entry;
			}
		}

		$placed = $this->placed_at( $order );

		if ( null !== $placed && ! isset( $records[ StageMap::PLACED ] ) && '' !== $this->stages->label( StageMap::PLACED ) ) {
			$records[ StageMap::PLACED ] = array(
				'status'        => '',
				'stage'         => StageMap::PLACED,
				'timestamp_utc' => $placed,
			);
		}

		return $records;
	}

	/**
	 * The order's creation time, in the format entries use.
	 *
	 * @since 0.3.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return string|null Null when the order has no creation date, which drafts can hit.
	 */
	private function placed_at( \WC_Order $order ): ?string {
		$created = $order->get_date_created();

		if ( ! $created instanceof \WC_DateTime ) {
			return null;
		}

		// Clone: WC_DateTime is mutable and this one belongs to the order.
		$utc = ( clone $created )->setTimezone( new \DateTimeZone( 'UTC' ) );

		return $utc->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The furthest progress stage the order has reached.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, array{status: string, stage: string, timestamp_utc: string}> $records Records by stage.
	 * @param string|null                                                                $current Current progress stage, if the order is on the line.
	 * @return int Position, or -1 when no progress stage has been reached.
	 */
	private function reached( array $records, ?string $current ): int {
		$reached = -1;

		foreach ( array_keys( $records ) as $stage ) {
			$reached = max( $reached, $this->stages->position( (string) $stage ) );
		}

		if ( null !== $current ) {
			// The live status outranks the record: a store that stopped recording
			// still knows where the order is now.
			$reached = max( $reached, $this->stages->position( $current ) );
		}

		return $reached;
	}

	/**
	 * The state of one stage against how far the order got.
	 *
	 * @since 0.3.0
	 *
	 * @param int  $position  Stage position.
	 * @param int  $reached   Furthest position reached.
	 * @param bool $branched  Whether the order left the progress line.
	 * @return string One of the TimelineStage STATE_* constants.
	 */
	private function state( int $position, int $reached, bool $branched ): string {
		if ( $position > $reached ) {
			return TimelineStage::STATE_PENDING;
		}

		if ( $position < $reached || $branched ) {
			// A branched order sits on its branch, so no progress stage is current.
			return TimelineStage::STATE_COMPLETE;
		}

		return TimelineStage::STATE_CURRENT;
	}

	/**
	 * Whether the order predates transition recording.
	 *
	 * Decided by the stage the order is in rather than by the whole array: an
	 * order placed before the plugin existed and completed after it has real
	 * dates, and one placed after but never moved has nothing missing. What
	 * makes a timeline historical is having no record of where it is now.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, TimelineStage> $stages  Built progress stages.
	 * @param TimelineStage|null        $branch  Built branch state.
	 * @param string|null               $current Current stage key.
	 * @return bool
	 */
	private function is_historical( array $stages, ?TimelineStage $branch, ?string $current ): bool {
		if ( null === $current ) {
			// An unmapped status tells us nothing about where the order is, so the
			// timeline is showing a shape rather than a history either way.
			return true;
		}

		if ( null !== $branch ) {
			return ! $branch->has_timestamp();
		}

		foreach ( $stages as $stage ) {
			if ( $stage->key === $current ) {
				return ! $stage->has_timestamp();
			}
		}

		return true;
	}
}
