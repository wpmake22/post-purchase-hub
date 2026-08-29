<?php
/**
 * Timeline stage definitions and status mapping.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * Describes the stages an order moves through and which status lands on which.
 *
 * Two kinds of stage live here. **Progress stages** are ordered and cumulative:
 * reaching one implies the earlier ones happened. **Branch stages** are not on
 * that line at all — an order is cancelled or refunded *instead of* continuing,
 * so they are rendered beside the progression rather than inside it.
 *
 * The default map covers WooCommerce's own statuses only. Packed, shipped and
 * out-for-delivery have no core status behind them, which is deliberate: they
 * exist so a store using custom fulfilment statuses can map onto them, and the
 * setup wizard narrows the visible set using detect_used_statuses(). Guessing at
 * third-party status slugs here would be worse than showing the merchant what
 * their own store actually uses.
 *
 * @since 0.3.0
 */
final class StageMap {

	const PLACED           = 'placed';
	const CONFIRMED        = 'confirmed';
	const PACKED           = 'packed';
	const SHIPPED          = 'shipped';
	const OUT_FOR_DELIVERY = 'out_for_delivery';
	const DELIVERED        = 'delivered';

	const CANCELLED = 'cancelled';
	const REFUNDED  = 'refunded';
	const FAILED    = 'failed';
	const ON_HOLD   = 'on_hold';

	/**
	 * Detector reporting which statuses this store really uses.
	 *
	 * @var StatusDetector
	 */
	private StatusDetector $detector;

	/**
	 * Memoised progress stages.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $stages = null;

	/**
	 * Memoised status-to-stage map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $map = null;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param StatusDetector $detector Detector reporting the statuses in use.
	 */
	public function __construct( StatusDetector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * The ordered progress stages, as stage key => label.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public function stages(): array {
		if ( null !== $this->stages ) {
			return $this->stages;
		}

		/**
		 * Filters the ordered progress stages of the order timeline.
		 *
		 * Keys are stage identifiers and values are translated labels. Order is
		 * significant: it is the order stages render in and the order progress is
		 * measured against. Branch states (cancelled, refunded, failed, on hold)
		 * are not part of this list.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, string> $stages Stage key => label.
		 */
		$filtered = apply_filters( 'wpmphub_timeline_stages', self::default_stages() );

		$this->stages = self::clean_labels( $filtered, self::default_stages() );

		return $this->stages;
	}

	/**
	 * The branch stages, as stage key => label.
	 *
	 * Not filterable: a branch stage without matching handling in eligibility,
	 * emails and the admin queue is a broken state rather than an extension
	 * point. Statuses can still be pointed at a different branch through
	 * `wpmphub_status_stage_map`.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public function branches(): array {
		return array(
			self::ON_HOLD   => _x( 'On hold', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::CANCELLED => _x( 'Cancelled', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::REFUNDED  => _x( 'Refunded', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::FAILED    => _x( 'Failed', 'order timeline stage', 'wpmake-post-purchase-hub' ),
		);
	}

	/**
	 * The status-to-stage map, as unprefixed status slug => stage key.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public function status_map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		/**
		 * Filters which order status lands on which timeline stage.
		 *
		 * Keys are unprefixed status slugs (`processing`, not `wc-processing`).
		 * Values are stage keys from either stages() or branches(). A status
		 * absent from the map contributes nothing to the timeline, which is how
		 * an internal status is kept off the customer's page.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, string> $map Status slug => stage key.
		 */
		$filtered = apply_filters( 'wpmphub_status_stage_map', self::default_status_map() );

		$this->map = $this->clean_map( $filtered );

		return $this->map;
	}

	/**
	 * The stage a status lands on, or null when the status is unmapped.
	 *
	 * @since 0.3.0
	 *
	 * @param string $status Order status, with or without the `wc-` prefix.
	 * @return string|null
	 */
	public function stage_for_status( string $status ): ?string {
		$status = self::normalise_status( $status );

		return $this->status_map()[ $status ] ?? null;
	}

	/**
	 * Whether a stage key is a branch state rather than a progress stage.
	 *
	 * @since 0.3.0
	 *
	 * @param string $stage Stage key.
	 * @return bool
	 */
	public function is_branch( string $stage ): bool {
		return isset( $this->branches()[ $stage ] );
	}

	/**
	 * The zero-based position of a progress stage, or -1 if it has none.
	 *
	 * @since 0.3.0
	 *
	 * @param string $stage Stage key.
	 * @return int
	 */
	public function position( string $stage ): int {
		$position = array_search( $stage, array_keys( $this->stages() ), true );

		return false === $position ? -1 : (int) $position;
	}

	/**
	 * The label for any stage key, progress or branch.
	 *
	 * @since 0.3.0
	 *
	 * @param string $stage Stage key.
	 * @return string Empty string for an unknown stage.
	 */
	public function label( string $stage ): string {
		return $this->stages()[ $stage ] ?? ( $this->branches()[ $stage ] ?? '' );
	}

	/**
	 * The statuses this store has actually used recently.
	 *
	 * Feeds the setup wizard, which is the fix for a six-stage timeline sitting
	 * on a store that only ever goes processing → completed.
	 *
	 * @since 0.3.0
	 *
	 * @param bool $refresh Skip the cached answer and read the sample again.
	 * @return array<int, string> Unprefixed status slugs, alphabetical.
	 */
	public function detect_used_statuses( bool $refresh = false ): array {
		return $this->detector->detect( $refresh );
	}

	/**
	 * Drops the cached detection result.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function forget_used_statuses(): void {
		$this->detector->forget();
	}

	/**
	 * Strips WooCommerce's `wc-` storage prefix from a status slug.
	 *
	 * @since 0.3.0
	 *
	 * @param string $status Order status.
	 * @return string
	 */
	public static function normalise_status( string $status ): string {
		$status = strtolower( trim( $status ) );

		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * The progress stages before any filtering.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	private static function default_stages(): array {
		return array(
			self::PLACED           => _x( 'Placed', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::CONFIRMED        => _x( 'Confirmed', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::PACKED           => _x( 'Packed', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::SHIPPED          => _x( 'Shipped', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::OUT_FOR_DELIVERY => _x( 'Out for delivery', 'order timeline stage', 'wpmake-post-purchase-hub' ),
			self::DELIVERED        => _x( 'Delivered', 'order timeline stage', 'wpmake-post-purchase-hub' ),
		);
	}

	/**
	 * The status map before any filtering.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	private static function default_status_map(): array {
		return array(
			'pending'    => self::PLACED,
			'processing' => self::CONFIRMED,
			'completed'  => self::DELIVERED,
			'on-hold'    => self::ON_HOLD,
			'cancelled'  => self::CANCELLED,
			'refunded'   => self::REFUNDED,
			'failed'     => self::FAILED,
		);
	}

	/**
	 * Reduces a filtered label list to usable string pairs.
	 *
	 * A filter that returns nothing usable falls back to the defaults rather
	 * than to an empty timeline: a third-party mistake must not blank the
	 * customer's order page.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed                 $filtered Filter result.
	 * @param array<string, string> $fallback Value to use when nothing survives.
	 * @return array<string, string>
	 */
	private static function clean_labels( $filtered, array $fallback ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$clean = array();

		foreach ( $filtered as $key => $label ) {
			if ( is_string( $key ) && '' !== $key && is_string( $label ) && '' !== $label ) {
				$clean[ $key ] = $label;
			}
		}

		return array() === $clean ? $fallback : $clean;
	}

	/**
	 * Reduces a filtered status map to pairs pointing at stages that exist.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $filtered Filter result.
	 * @return array<string, string>
	 */
	private function clean_map( $filtered ): array {
		if ( ! is_array( $filtered ) ) {
			return self::default_status_map();
		}

		$known = $this->stages() + $this->branches();
		$clean = array();

		foreach ( $filtered as $status => $stage ) {
			if ( ! is_string( $status ) || ! is_string( $stage ) || ! isset( $known[ $stage ] ) ) {
				continue;
			}

			$status = self::normalise_status( $status );

			if ( '' !== $status ) {
				$clean[ $status ] = $stage;
			}
		}

		return $clean;
	}
}
