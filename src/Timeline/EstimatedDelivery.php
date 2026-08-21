<?php
/**
 * Estimated-delivery calculation and caching.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;
use PostPurchaseHub\Support\Dates;
use PostPurchaseHub\Support\Logger;

/**
 * Turns handling time and shipping transit config into a delivery estimate.
 *
 * This is the only part of the WISMO promise the plugin can keep without a
 * tracking plugin, so it fails toward silence rather than toward a guess:
 * no shipping line, no configured transit time for the method in use, or a
 * range that has already passed all mean nothing is shown — never a
 * placeholder, never an error.
 *
 * Once computed, a range is cached on the order and stays what was quoted
 * even if the store's handling-time or transit settings change afterwards.
 * A merchant editing next week's settings must not silently rewrite a promise
 * already shown to last week's customer; only something about *this* order —
 * its status or its shipping line — recomputes and rewrites its cache.
 *
 * That write only ever happens from {@see sync()}, called from the order
 * events that already exist for other reasons — the shipping line being
 * created at checkout, a later status change, a later shipping-line edit —
 * never from {@see for_order()}. Hard rule 4 forbids a state mutation on GET,
 * and the customer's own order page renders over one; a cache that only
 * fills itself the first time someone *looks* would violate that the moment
 * it fired from a page view. `for_order()` reads the cache and, on a miss,
 * computes the same answer in memory for that one response without saving
 * it — a page view always gets a correct estimate, but only ever an order
 * event persists one.
 *
 * @since 0.5.0
 */
final class EstimatedDelivery {

	/**
	 * Order meta key holding the cached range.
	 *
	 * @var string
	 */
	public const META_KEY = '_pph_eta';

	/**
	 * Settings key: global handling time, in business days.
	 *
	 * @var string
	 */
	public const HANDLING_SETTING = 'eta_handling_days';

	/**
	 * Settings key: per-shipping-method handling time overrides.
	 *
	 * @var string
	 */
	public const HANDLING_OVERRIDES_SETTING = 'eta_handling_days_by_method';

	/**
	 * Settings key: per-shipping-method transit min/max, in business days.
	 *
	 * @var string
	 */
	public const TRANSIT_SETTING = 'eta_transit_days_by_method';

	/**
	 * Settings key: days of the week treated as non-business days.
	 *
	 * @var string
	 */
	public const WEEKEND_SETTING = 'eta_weekend_days';

	/**
	 * Settings key: non-business dates.
	 *
	 * @var string
	 */
	public const HOLIDAYS_SETTING = 'eta_holidays';

	/**
	 * Global handling time used when nothing is configured.
	 *
	 * @var int
	 */
	public const DEFAULT_HANDLING_DAYS = 1;

	/**
	 * Tracking-availability check.
	 *
	 * @var TrackingAvailability
	 */
	private TrackingAvailability $tracking;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param TrackingAvailability $tracking Tracking-availability check.
	 * @param Logger               $logger   Logger.
	 */
	public function __construct( TrackingAvailability $tracking, Logger $logger ) {
		$this->tracking = $tracking;
		$this->logger   = $logger;
	}

	/**
	 * The estimated-delivery range for an order, or null when there is none.
	 *
	 * Read-only: never writes to the order, even on a cache miss. See the
	 * class docblock for why — this is what keeps a page view from mutating
	 * anything.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return EstimatedDeliveryRange|null
	 */
	public function for_order( \WC_Order $order ): ?EstimatedDeliveryRange {
		if ( $this->tracking->has_tracking( $order ) ) {
			// Real tracking outranks a guess, unconditionally: no filter gets a
			// vote on this, or the suppression this milestone requires would only
			// ever be as reliable as every filter callback a store has installed.
			return null;
		}

		$range = $this->cached( $order ) ?? $this->calculate( $order );
		$range = $this->filtered( $range, $order );

		if ( null !== $range && $range->has_passed( new \DateTimeImmutable( 'now', Dates::store_timezone() ) ) ) {
			return null;
		}

		return $range;
	}

	/**
	 * Recomputes an order's range and rewrites its cache, in one save.
	 *
	 * The only method that writes `_pph_eta`. Callback for the order events
	 * that already exist for other reasons: the shipping line being created
	 * at checkout, a later status change, a later shipping-line edit. Safe to
	 * call whether or not anything is cached yet, and safe to call when the
	 * order no longer has an answer — real tracking now exists, or its
	 * shipping method dropped out of the configured set — in which case any
	 * stale cache is cleared instead of replaced.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to resync.
	 * @return void
	 */
	public function sync( \WC_Order $order ): void {
		$range = $this->tracking->has_tracking( $order ) ? null : $this->calculate( $order );

		if ( null === $range ) {
			$this->clear_cache( $order );

			return;
		}

		$this->write_cache( $order, $range );
	}

	/**
	 * Computes a fresh range from handling time and shipping transit config.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return EstimatedDeliveryRange|null
	 */
	private function calculate( \WC_Order $order ): ?EstimatedDeliveryRange {
		$method = $this->shipping_method_id( $order );

		if ( null === $method ) {
			return null;
		}

		$transit = $this->transit_days( $method );

		if ( null === $transit ) {
			// Unconfigured shipping method: no ETA, no placeholder, no error.
			return null;
		}

		$placed = $this->placed_at( $order );

		if ( null === $placed ) {
			return null;
		}

		$weekend  = $this->weekend_days();
		$holidays = $this->holidays();
		$placed   = $placed->setTimezone( Dates::store_timezone() );

		$ready = Dates::add_business_days( $placed, $this->handling_days( $method ), $weekend, $holidays );
		$start = Dates::add_business_days( $ready, $transit['min'], $weekend, $holidays );
		$end   = Dates::add_business_days( $ready, $transit['max'], $weekend, $holidays );

		return new EstimatedDeliveryRange( $start, $end, $this->label( $start, $end ) );
	}

	/**
	 * The method id of the order's first shipping line, if it has one.
	 *
	 * An order can carry more than one shipping line (split shipments), but
	 * v1 quotes a single estimate, so the first line drives it — the same
	 * simplification the timeline makes by describing one order as one
	 * journey.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return string|null
	 */
	private function shipping_method_id( \WC_Order $order ): ?string {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping ) {
				$method_id = $item->get_method_id();

				if ( '' !== $method_id ) {
					return $method_id;
				}
			}
		}

		return null;
	}

	/**
	 * The order's creation moment, unconverted.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return \DateTimeImmutable|null
	 */
	private function placed_at( \WC_Order $order ): ?\DateTimeImmutable {
		$created = $order->get_date_created();

		if ( ! $created instanceof \WC_DateTime ) {
			return null;
		}

		// Clone: WC_DateTime is mutable and this one belongs to the order.
		return \DateTimeImmutable::createFromMutable( clone $created );
	}

	/**
	 * Handling time in business days for a shipping method.
	 *
	 * @since 0.5.0
	 *
	 * @param string $method_id Shipping method id.
	 * @return int
	 */
	private function handling_days( string $method_id ): int {
		$settings  = $this->settings();
		$default   = isset( $settings[ self::HANDLING_SETTING ] ) ? (int) $settings[ self::HANDLING_SETTING ] : self::DEFAULT_HANDLING_DAYS;
		$overrides = is_array( $settings[ self::HANDLING_OVERRIDES_SETTING ] ?? null ) ? $settings[ self::HANDLING_OVERRIDES_SETTING ] : array();
		$days      = isset( $overrides[ $method_id ] ) ? (int) $overrides[ $method_id ] : $default;

		/**
		 * Filters the handling time, in business days, for a shipping method.
		 *
		 * @since 0.5.0
		 *
		 * @param int    $days      Handling time in business days.
		 * @param string $method_id Shipping method id the estimate is being built for.
		 */
		return max( 0, (int) apply_filters( 'pph_estimated_delivery_handling_days', $days, $method_id ) );
	}

	/**
	 * Transit time in business days for a shipping method, or null when unconfigured.
	 *
	 * @since 0.5.0
	 *
	 * @param string $method_id Shipping method id.
	 * @return array{min: int, max: int}|null
	 */
	private function transit_days( string $method_id ): ?array {
		$settings = $this->settings();
		$map      = is_array( $settings[ self::TRANSIT_SETTING ] ?? null ) ? $settings[ self::TRANSIT_SETTING ] : array();
		$config   = is_array( $map[ $method_id ] ?? null ) ? $map[ $method_id ] : null;

		/**
		 * Filters the transit-time config for a shipping method.
		 *
		 * Null — the default for a method with no configured entry — means no
		 * estimate is shown for it at all: an honest "we don't know" rather than
		 * a placeholder.
		 *
		 * @since 0.5.0
		 *
		 * @param array{min: int, max: int}|null $config    Transit config, or null when unconfigured.
		 * @param string                          $method_id Shipping method id the estimate is being built for.
		 */
		$config = apply_filters( 'pph_estimated_delivery_transit_days', $config, $method_id );

		if ( ! is_array( $config ) || ! isset( $config['min'], $config['max'] ) ) {
			return null;
		}

		$min = max( 0, (int) $config['min'] );
		$max = max( 0, (int) $config['max'] );

		return array(
			'min' => min( $min, $max ),
			'max' => max( $min, $max ),
		);
	}

	/**
	 * The days of the week treated as non-business days.
	 *
	 * @since 0.5.0
	 *
	 * @return array<int, int>
	 */
	private function weekend_days(): array {
		$settings = $this->settings();
		$days     = is_array( $settings[ self::WEEKEND_SETTING ] ?? null ) ? $settings[ self::WEEKEND_SETTING ] : Dates::DEFAULT_WEEKEND_DAYS;

		/**
		 * Filters the days of the week treated as non-business days.
		 *
		 * Values are `DateTimeInterface::format('w')` numbers: 0 (Sunday)
		 * through 6 (Saturday). Lets a store whose weekend is Friday/Saturday
		 * configure it without waiting on a settings screen.
		 *
		 * @since 0.5.0
		 *
		 * @param array<int, int> $days Non-business days of the week.
		 */
		$days = apply_filters( 'pph_estimated_delivery_weekend_days', $days );

		$clean = array();

		foreach ( is_array( $days ) ? $days : array() as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
				$clean[] = (int) $day;
			}
		}

		return array() === $clean ? Dates::DEFAULT_WEEKEND_DAYS : array_values( array_unique( $clean ) );
	}

	/**
	 * The configured non-business dates.
	 *
	 * @since 0.5.0
	 *
	 * @return array<int, string>
	 */
	private function holidays(): array {
		$settings = $this->settings();
		$holidays = is_array( $settings[ self::HOLIDAYS_SETTING ] ?? null ) ? $settings[ self::HOLIDAYS_SETTING ] : array();

		/**
		 * Filters the store's non-business dates.
		 *
		 * @since 0.5.0
		 *
		 * @param array<int, string> $holidays Holiday dates as `Y-m-d` strings.
		 */
		$holidays = apply_filters( 'pph_estimated_delivery_holidays', $holidays );

		$clean = array();

		foreach ( is_array( $holidays ) ? $holidays : array() as $date ) {
			if ( is_string( $date ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$clean[] = $date;
			}
		}

		return $clean;
	}

	/**
	 * The plugin's settings option, defensively typed.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = get_option( 'pph_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * A localised range label.
	 *
	 * @since 0.5.0
	 *
	 * @param \DateTimeImmutable $start Range start.
	 * @param \DateTimeImmutable $end   Range end.
	 * @return string
	 */
	private function label( \DateTimeImmutable $start, \DateTimeImmutable $end ): string {
		/**
		 * Filters the date format used for the estimated-delivery label.
		 *
		 * @since 0.5.0
		 *
		 * @param string $format Date format string for wp_date().
		 */
		$format = (string) apply_filters( 'pph_estimated_delivery_date_format', (string) get_option( 'date_format', 'F j, Y' ) );

		$start_label = (string) wp_date( $format, $start->getTimestamp(), $start->getTimezone() );
		$end_label   = (string) wp_date( $format, $end->getTimestamp(), $end->getTimezone() );

		if ( $start_label === $end_label ) {
			return $start_label;
		}

		return sprintf(
			/* translators: 1: earliest estimated delivery date, 2: latest estimated delivery date. */
			__( '%1$s – %2$s', 'post-purchase-hub' ),
			$start_label,
			$end_label
		);
	}

	/**
	 * Reads a cached range from order meta, discarding anything malformed.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to read.
	 * @return EstimatedDeliveryRange|null
	 */
	private function cached( \WC_Order $order ): ?EstimatedDeliveryRange {
		$raw = $order->get_meta( self::META_KEY, true );

		if ( '' === $raw || array() === $raw ) {
			return null;
		}

		if ( ! is_array( $raw ) || ! isset( $raw['start'], $raw['end'] ) || ! is_string( $raw['start'] ) || ! is_string( $raw['end'] ) ) {
			$this->logger->warning( 'Discarded malformed estimated-delivery cache.', array( 'order_id' => $order->get_id() ) );

			return null;
		}

		$tz    = Dates::store_timezone();
		$start = $this->from_stored( $raw['start'], $tz );
		$end   = $this->from_stored( $raw['end'], $tz );

		if ( null === $start || null === $end ) {
			$this->logger->warning( 'Discarded malformed estimated-delivery cache.', array( 'order_id' => $order->get_id() ) );

			return null;
		}

		return new EstimatedDeliveryRange( $start, $end, $this->label( $start, $end ) );
	}

	/**
	 * Parses a stored UTC timestamp into the store's timezone.
	 *
	 * @since 0.5.0
	 *
	 * @param string        $utc Stored `Y-m-d H:i:s` in UTC.
	 * @param \DateTimeZone $tz  Timezone to present it in.
	 * @return \DateTimeImmutable|null
	 */
	private function from_stored( string $utc, \DateTimeZone $tz ): ?\DateTimeImmutable {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );

		return false === $parsed ? null : $parsed->setTimezone( $tz );
	}

	/**
	 * Writes a computed range to order meta and saves.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order              $order Order to write to.
	 * @param EstimatedDeliveryRange $range Range to cache.
	 * @return void
	 */
	private function write_cache( \WC_Order $order, EstimatedDeliveryRange $range ): void {
		$utc = new \DateTimeZone( 'UTC' );

		$order->update_meta_data(
			self::META_KEY,
			array(
				'start' => $range->start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end'   => $range->end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			)
		);

		$order->save();
	}

	/**
	 * Removes a cached range, if one exists.
	 *
	 * @since 0.5.0
	 *
	 * @param \WC_Order $order Order to clear.
	 * @return void
	 */
	private function clear_cache( \WC_Order $order ): void {
		if ( '' === $order->get_meta( self::META_KEY, true ) ) {
			return;
		}

		$order->delete_meta_data( self::META_KEY );
		$order->save();
	}

	/**
	 * Applies the public filter, defensively.
	 *
	 * @since 0.5.0
	 *
	 * @param EstimatedDeliveryRange|null $range Computed range, or null.
	 * @param \WC_Order                   $order Order being described.
	 * @return EstimatedDeliveryRange|null
	 */
	private function filtered( ?EstimatedDeliveryRange $range, \WC_Order $order ): ?EstimatedDeliveryRange {
		/**
		 * Filters the estimated-delivery range shown for an order.
		 *
		 * Receives whatever this plugin computed — possibly null, when no
		 * shipping method is configured or no shipping line exists at all —
		 * and the order it is for.
		 *
		 * @since 0.5.0
		 *
		 * @param EstimatedDeliveryRange|null $range Computed range, or null.
		 * @param \WC_Order                   $order Order being described.
		 */
		$filtered = apply_filters( 'pph_estimated_delivery', $range, $order );

		if ( null === $filtered || $filtered instanceof EstimatedDeliveryRange ) {
			return $filtered;
		}

		$this->logger->warning(
			'Ignored a pph_estimated_delivery filter that returned an invalid type.',
			array(
				'order_id' => $order->get_id(),
				'type'     => get_debug_type( $filtered ),
			)
		);

		return $range;
	}
}
