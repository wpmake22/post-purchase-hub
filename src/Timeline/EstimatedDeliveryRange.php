<?php
/**
 * Computed estimated-delivery range.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * A start/end delivery estimate, already localised for display.
 *
 * Immutable and storage-free, like Timeline and TimelineStage: built once by
 * EstimatedDelivery and handed to a template with nothing left to decide.
 * `label` is resolved through `wp_date()` at construction time rather than by
 * whatever renders this later, because the store's date format and locale are
 * exactly the same choice `wp_date()` already makes for the rest of the order
 * page — a template computing its own copy would only be one more place that
 * choice could drift out of step.
 *
 * @since 0.5.0
 */
final class EstimatedDeliveryRange {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param \DateTimeImmutable $start Earliest estimated delivery moment, store timezone.
	 * @param \DateTimeImmutable $end   Latest estimated delivery moment, store timezone.
	 * @param string             $label Localised range, e.g. "March 3 – March 5, 2026".
	 */
	public function __construct(
		public readonly \DateTimeImmutable $start,
		public readonly \DateTimeImmutable $end,
		public readonly string $label
	) {}

	/**
	 * Whether the range is entirely behind the given moment.
	 *
	 * A range that has not started yet, or is still in progress, has not
	 * passed — only one whose latest end already precedes `$now` has.
	 *
	 * @since 0.5.0
	 *
	 * @param \DateTimeImmutable $now Moment to compare against.
	 * @return bool
	 */
	public function has_passed( \DateTimeImmutable $now ): bool {
		return $this->end < $now;
	}
}
