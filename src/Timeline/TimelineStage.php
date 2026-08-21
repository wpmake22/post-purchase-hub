<?php
/**
 * One stage of a built timeline.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * A single stage, resolved against one order.
 *
 * Immutable and storage-free, like Requests\Request: templates receive these
 * already decided, so no template ever has to work out whether a stage has been
 * reached. `timestamp` is null whenever the moment is genuinely unknown — the
 * timeline says "no date" rather than inventing one.
 *
 * @since 0.3.0
 */
final class TimelineStage {

	/**
	 * Stage the order has moved past.
	 *
	 * @var string
	 */
	const STATE_COMPLETE = 'complete';

	/**
	 * Stage the order is sitting in now.
	 *
	 * @var string
	 */
	const STATE_CURRENT = 'current';

	/**
	 * Stage the order has not reached.
	 *
	 * @var string
	 */
	const STATE_PENDING = 'pending';

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param string      $key       Stage key from StageMap.
	 * @param string      $label     Translated label.
	 * @param string      $state     One of the STATE_* constants.
	 * @param string|null $timestamp UTC `Y-m-d H:i:s`, or null when unknown.
	 * @param string|null $status    Order status recorded at this stage, or null when unknown.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $state,
		public readonly ?string $timestamp,
		public readonly ?string $status
	) {}

	/**
	 * Whether the order has reached this stage.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function is_reached(): bool {
		return self::STATE_PENDING !== $this->state;
	}

	/**
	 * Whether a timestamp is known for this stage.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function has_timestamp(): bool {
		return null !== $this->timestamp;
	}
}
