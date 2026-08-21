<?php
/**
 * Built timeline view model.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * Everything a template needs to render one order's timeline, and nothing else.
 *
 * Built by TimelineBuilder and never mutated afterwards. It carries no order
 * object, so a template holding one cannot reach back into CRUD and start
 * querying — the boundary that keeps templates logic-free is this class.
 *
 * @since 0.3.0
 */
final class Timeline {

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param int                       $order_id   Order this describes.
	 * @param string                    $status     Current unprefixed order status.
	 * @param array<int, TimelineStage> $stages    Progress stages, in order.
	 * @param TimelineStage|null        $branch     Branch state the order ended on, if any.
	 * @param bool                      $historical Whether the current stage has no recorded timestamp.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $status,
		public readonly array $stages,
		public readonly ?TimelineStage $branch,
		public readonly bool $historical
	) {}

	/**
	 * The stage the order is sitting in, branch state included.
	 *
	 * @since 0.3.0
	 *
	 * @return TimelineStage|null Null when the status maps to no stage at all.
	 */
	public function current(): ?TimelineStage {
		if ( null !== $this->branch ) {
			return $this->branch;
		}

		foreach ( $this->stages as $stage ) {
			if ( TimelineStage::STATE_CURRENT === $stage->state ) {
				return $stage;
			}
		}

		return null;
	}

	/**
	 * Whether the order left the progress line for a branch state.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function has_branched(): bool {
		return null !== $this->branch;
	}

	/**
	 * Whether any stage carries a timestamp.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function has_timestamps(): bool {
		foreach ( $this->stages as $stage ) {
			if ( $stage->has_timestamp() ) {
				return true;
			}
		}

		return null !== $this->branch && $this->branch->has_timestamp();
	}
}
