<?php
/**
 * Presentation layer for the timeline view model.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Timeline\Timeline;
use PostPurchaseHub\Timeline\TimelineStage;

/**
 * Turns a Timeline into the strings a template echoes.
 *
 * Exists so templates hold no logic at all, per hard rule 10. Everything a
 * template needs — the state word, the machine-readable datetime, the localised
 * date, the notice for an order with no recorded history — is decided here and
 * arrives as an escaped-on-output primitive.
 *
 * Timestamps are stored UTC and displayed in the store's timezone through
 * wp_date(), because a customer reading "shipped at 23:40" should see the time
 * the store would quote them, not the server's.
 *
 * @since 0.4.0
 */
final class TimelineView {

	/**
	 * Builds the display array for one timeline.
	 *
	 * `$pending_cancellation` overlays a "Cancellation requested" branch when
	 * the order has not itself branched — set only on the single-order detail
	 * view (see `Requests\PendingCancellationBranch`), never on a list, where
	 * finding it out would cost a query per row. A real branch always wins:
	 * an order that has actually been cancelled or refunded does not also
	 * show a still-pending request.
	 *
	 * @since 0.4.0
	 *
	 * @param Timeline                                                       $timeline              Built timeline.
	 * @param array{label: string, timestamp_utc: string, note: string}|null $pending_cancellation Pending-cancellation overlay, or null.
	 * @return array{order_id: int, status: string, historical: bool, notice: string, stages: array<int, array{key: string, label: string, state: string, state_label: string, datetime: string, date_label: string}>, branch: array{key: string, label: string, state: string, state_label: string, datetime: string, date_label: string}|null, branch_note: string, current: array{key: string, label: string, state: string, state_label: string, datetime: string, date_label: string}|null}
	 */
	public static function present( Timeline $timeline, ?array $pending_cancellation = null ): array {
		$stages = array();

		foreach ( $timeline->stages as $stage ) {
			$stages[] = self::stage( $stage );
		}

		$branch      = null === $timeline->branch ? null : self::stage( $timeline->branch );
		$branch_note = '';

		if ( null === $branch && null !== $pending_cancellation ) {
			$branch      = self::pending_cancellation_branch( $pending_cancellation );
			$branch_note = $pending_cancellation['note'];
		}

		if ( null !== $branch ) {
			$current = $branch;
		} else {
			$current_stage = $timeline->current();
			$current       = null === $current_stage ? null : self::stage( $current_stage );
		}

		return array(
			'order_id'    => $timeline->order_id,
			'status'      => $timeline->status,
			'historical'  => $timeline->historical,
			'notice'      => $timeline->historical ? self::notice() : '',
			'stages'      => $stages,
			'branch'      => $branch,
			'branch_note' => $branch_note,
			'current'     => $current,
		);
	}

	/**
	 * Builds the display array for the pending-cancellation branch overlay.
	 *
	 * @since 0.8.0
	 *
	 * @param array{label: string, timestamp_utc: string, note: string} $pending Overlay data.
	 * @return array{key: string, label: string, state: string, state_label: string, datetime: string, date_label: string}
	 */
	private static function pending_cancellation_branch( array $pending ): array {
		$timestamp = self::timestamp( $pending['timestamp_utc'] );

		return array(
			'key'         => 'cancellation_requested',
			'label'       => $pending['label'],
			'state'       => TimelineStage::STATE_CURRENT,
			'state_label' => self::state_label( TimelineStage::STATE_CURRENT ),
			'datetime'    => null === $timestamp ? '' : gmdate( 'c', $timestamp ),
			'date_label'  => null === $timestamp ? '' : (string) wp_date( self::format(), $timestamp ),
		);
	}

	/**
	 * Builds the display array for one stage.
	 *
	 * @since 0.4.0
	 *
	 * @param TimelineStage $stage Stage to present.
	 * @return array{key: string, label: string, state: string, state_label: string, datetime: string, date_label: string}
	 */
	private static function stage( TimelineStage $stage ): array {
		$timestamp = self::timestamp( $stage->timestamp );

		return array(
			'key'         => $stage->key,
			'label'       => $stage->label,
			'state'       => $stage->state,
			'state_label' => self::state_label( $stage->state ),
			'datetime'    => null === $timestamp ? '' : gmdate( 'c', $timestamp ),
			'date_label'  => null === $timestamp ? '' : (string) wp_date( self::format(), $timestamp ),
		);
	}

	/**
	 * The word describing a stage's state.
	 *
	 * Spelled out because the timeline must not communicate state through colour
	 * or position alone: a screen reader gets the same information a sighted
	 * customer does.
	 *
	 * @since 0.4.0
	 *
	 * @param string $state One of the TimelineStage STATE_* constants.
	 * @return string
	 */
	private static function state_label( string $state ): string {
		switch ( $state ) {
			case TimelineStage::STATE_COMPLETE:
				return _x( 'Done', 'order timeline stage state', 'post-purchase-hub' );
			case TimelineStage::STATE_CURRENT:
				return _x( 'In progress', 'order timeline stage state', 'post-purchase-hub' );
			default:
				return _x( 'Not yet', 'order timeline stage state', 'post-purchase-hub' );
		}
	}

	/**
	 * The message shown when an order has no recorded history.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	private static function notice(): string {
		return __( 'This order was placed before detailed tracking was switched on, so exact dates are not available.', 'post-purchase-hub' );
	}

	/**
	 * Converts a stored UTC timestamp to a Unix timestamp.
	 *
	 * @since 0.4.0
	 *
	 * @param string|null $stored Stored `Y-m-d H:i:s` in UTC.
	 * @return int|null
	 */
	private static function timestamp( ?string $stored ): ?int {
		if ( null === $stored || '' === $stored ) {
			return null;
		}

		$parsed = strtotime( $stored . ' UTC' );

		return false === $parsed ? null : $parsed;
	}

	/**
	 * The store's configured date and time format.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	private static function format(): string {
		/**
		 * Filters the date format used for timeline timestamps.
		 *
		 * Defaults to the store's own date and time settings, which is almost
		 * always what a merchant wants and almost never what they want to state
		 * twice.
		 *
		 * @since 0.4.0
		 *
		 * @param string $format Date format string for wp_date().
		 */
		return (string) apply_filters(
			'pph_timeline_date_format',
			trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) )
		);
	}
}
