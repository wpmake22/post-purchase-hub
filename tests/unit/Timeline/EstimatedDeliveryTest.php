<?php
/**
 * Estimated-delivery unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;
use PostPurchaseHub\Support\Dates;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\EstimatedDeliveryRange;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers computation, caching and suppression, independently of the business-day
 * arithmetic itself, which {@see \PostPurchaseHub\Tests\Unit\Support\DatesTest}
 * already covers.
 *
 * Every order placed in these tests is dated well into the future rather than
 * on a real calendar date near "now": `for_order()` refuses to return a range
 * that has already passed, so a fixed date chosen for readability would start
 * failing the moment the calendar caught up to it. Where a scenario needs a
 * range to have already passed, it uses a fixed date far in the past instead.
 *
 * @since 0.5.0
 *
 * @covers \PostPurchaseHub\Timeline\EstimatedDelivery
 * @covers \PostPurchaseHub\Timeline\EstimatedDeliveryRange
 */
final class EstimatedDeliveryTest extends TestCase {

	/**
	 * A placement moment safely in the future, whatever "now" is when this runs.
	 *
	 * @var string
	 */
	private const FUTURE = '2099-06-02 09:00:00';

	/**
	 * A placement moment long in the past.
	 *
	 * @var string
	 */
	private const PAST = '2000-01-03 09:00:00';

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Builds an order with one shipping line and a creation date.
	 *
	 * @param string $method_id Shipping method id, or '' for no shipping line.
	 * @param string $created   Creation moment, parseable by DateTime.
	 * @return \WC_Order
	 */
	private function order( string $method_id, string $created ): \WC_Order {
		$order = new \WC_Order( 1, 'processing' );
		$order->set_date( 'created', new \WC_DateTime( $created, new \DateTimeZone( 'UTC' ) ) );

		if ( '' !== $method_id ) {
			$order->set_shipping_methods( array( new \WC_Order_Item_Shipping( $method_id, $order->get_id() ) ) );
		}

		return $order;
	}

	/**
	 * Configures the plugin's settings option.
	 *
	 * @param array<string, mixed> $settings Settings to store.
	 * @return void
	 */
	private function configure( array $settings ): void {
		update_option( 'pph_settings', $settings );
	}

	/**
	 * Builds the service under test.
	 *
	 * @param TrackingAvailability|null $tracking Tracking check, defaults to the stub.
	 * @return EstimatedDelivery
	 */
	private function service( ?TrackingAvailability $tracking = null ): EstimatedDelivery {
		return new EstimatedDelivery( $tracking ?? new NullTrackingAvailability(), new Logger() );
	}

	/**
	 * The range Dates::add_business_days() itself would produce, independently
	 * of EstimatedDelivery — used to assert the service wires handling time and
	 * transit config into that helper correctly, not to re-verify the helper.
	 *
	 * @param string $placed   Creation moment.
	 * @param int    $handling Handling days.
	 * @param int    $min      Transit minimum.
	 * @param int    $max      Transit maximum.
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
	 */
	private function expected( string $placed, int $handling, int $min, int $max ): array {
		$tz     = new \DateTimeZone( 'UTC' );
		$origin = new \DateTimeImmutable( $placed, $tz );
		$ready  = Dates::add_business_days( $origin, $handling );

		return array(
			'start' => Dates::add_business_days( $ready, $min ),
			'end'   => Dates::add_business_days( $ready, $max ),
		);
	}

	/**
	 * An order with no shipping line at all gets no estimate.
	 *
	 * @return void
	 */
	public function test_no_shipping_line_means_no_estimate(): void {
		$this->configure(
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 3,
						'max' => 5,
					),
				),
			)
		);

		$order = $this->order( '', self::FUTURE );

		$this->assertNull( $this->service()->for_order( $order ) );
		$this->assertSame( 0, $order->saves, 'Nothing to cache means nothing to save.' );
	}

	/**
	 * An unconfigured shipping method gets no estimate, no placeholder, no error.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_shipping_method_means_no_estimate(): void {
		$order = $this->order( 'local_pickup', self::FUTURE );

		$this->assertNull( $this->service()->for_order( $order ) );
		$this->assertSame( 0, $order->saves );
	}

	/**
	 * A configured method produces the range handling time and transit imply.
	 *
	 * @return void
	 */
	public function test_a_configured_method_computes_the_expected_range(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 2,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 3,
						'max' => 5,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::FUTURE );
		$range = $this->service()->for_order( $order );
		$want  = $this->expected( self::FUTURE, 2, 3, 5 );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $range );
		$this->assertSame( $want['start']->format( 'Y-m-d H:i:s' ), $range->start->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $want['end']->format( 'Y-m-d H:i:s' ), $range->end->format( 'Y-m-d H:i:s' ) );
		$this->assertNotSame( '', $range->label );
	}

	/**
	 * A per-method handling-time override wins over the global default.
	 *
	 * @return void
	 */
	public function test_a_per_method_handling_override_wins_over_the_default(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::HANDLING_OVERRIDES_SETTING => array( 'flat_rate' => 4 ),
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 0,
						'max' => 0,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::FUTURE );
		$range = $this->service()->for_order( $order );
		$want  = $this->expected( self::FUTURE, 4, 0, 0 );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $range );
		$this->assertSame( $want['start']->format( 'Y-m-d H:i:s' ), $range->start->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A range that has already passed is never returned.
	 *
	 * @return void
	 */
	public function test_a_past_range_returns_null(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 0,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 0,
						'max' => 0,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::PAST );

		$this->assertNull( $this->service()->for_order( $order ) );
	}

	/**
	 * Real tracking data suppresses the estimate unconditionally.
	 *
	 * @return void
	 */
	public function test_tracking_data_suppresses_the_estimate(): void {
		$this->configure(
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 2,
					),
				),
			)
		);

		$tracking               = new FakeTrackingAvailability();
		$tracking->has_tracking = true;

		$order = $this->order( 'flat_rate', self::FUTURE );

		$this->assertNull( $this->service( $tracking )->for_order( $order ) );
		$this->assertSame( 0, $order->saves, 'A suppressed estimate is never computed or cached.' );
	}

	/**
	 * Tracking data appearing after a range was synced clears it on the next sync.
	 *
	 * @return void
	 */
	public function test_sync_clears_the_cache_once_tracking_data_appears(): void {
		$this->configure(
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 2,
					),
				),
			)
		);

		$tracking = new FakeTrackingAvailability();
		$order    = $this->order( 'flat_rate', self::FUTURE );
		$service  = $this->service( $tracking );

		$service->sync( $order );
		$this->assertIsArray( $order->get_meta( EstimatedDelivery::META_KEY, true ) );

		$tracking->has_tracking = true;
		$service->sync( $order );

		$this->assertSame( '', $order->get_meta( EstimatedDelivery::META_KEY, true ) );
	}

	/**
	 * Reading an estimate never saves the order, cached or not.
	 *
	 * Hard rule 4: no state mutation on GET, and the customer's order page
	 * renders over one. A page view must get a correct answer without ever
	 * being the thing that first persists it.
	 *
	 * @return void
	 */
	public function test_for_order_never_saves(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::FUTURE );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $this->service()->for_order( $order ) );
		$this->assertInstanceOf( EstimatedDeliveryRange::class, $this->service()->for_order( $order ) );
		$this->assertSame( 0, $order->saves, 'for_order() must never write, on a miss or a hit.' );
	}

	/**
	 * Sync persists a range that for_order() then reads back unchanged.
	 *
	 * @return void
	 */
	public function test_sync_persists_a_range_that_for_order_then_reads_back(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order   = $this->order( 'flat_rate', self::FUTURE );
		$service = $this->service();

		$service->sync( $order );
		$this->assertSame( 1, $order->saves );

		$cached = $order->get_meta( EstimatedDelivery::META_KEY, true );
		$this->assertIsArray( $cached );

		$read = $service->for_order( $order );
		$this->assertInstanceOf( EstimatedDeliveryRange::class, $read );
		$this->assertSame( 1, $order->saves, 'Reading a cached range costs no save.' );
	}

	/**
	 * Once synced, a range stays what was quoted until synced again.
	 *
	 * @return void
	 */
	public function test_a_synced_range_stays_stable_until_synced_again(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order   = $this->order( 'flat_rate', self::FUTURE );
		$service = $this->service();

		$service->sync( $order );
		$first = $service->for_order( $order );

		// A settings change alone, with no order event, must not move it.
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 9,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 9,
						'max' => 9,
					),
				),
			)
		);

		$this->assertSame(
			$first->start->format( 'Y-m-d' ),
			$service->for_order( $order )->start->format( 'Y-m-d' )
		);

		// The order event this milestone requires: syncing again picks up the change.
		$service->sync( $order );
		$second = $service->for_order( $order );

		$this->assertNotSame( $first->start->format( 'Y-m-d' ), $second->start->format( 'Y-m-d' ) );
	}

	/**
	 * Syncing an order with nothing computable and nothing cached costs no save.
	 *
	 * @return void
	 */
	public function test_sync_is_a_no_op_when_there_is_nothing_to_cache_or_clear(): void {
		$order = $this->order( 'local_pickup', self::FUTURE );

		$this->service()->sync( $order );

		$this->assertSame( 0, $order->saves );
	}

	/**
	 * Syncing an order that lost its estimate clears a stale cached range.
	 *
	 * A shipping method dropping out of the configured transit map, or real
	 * tracking data appearing, both mean a previously cached range is now
	 * wrong and must not linger.
	 *
	 * @return void
	 */
	public function test_sync_clears_a_stale_cache_when_nothing_is_computable(): void {
		$this->configure(
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order   = $this->order( 'flat_rate', self::FUTURE );
		$service = $this->service();

		$service->sync( $order );
		$this->assertIsArray( $order->get_meta( EstimatedDelivery::META_KEY, true ) );

		$this->configure( array() );

		$service->sync( $order );

		$this->assertSame( '', $order->get_meta( EstimatedDelivery::META_KEY, true ) );
	}

	/**
	 * Malformed cached meta is discarded and the range is recomputed.
	 *
	 * @return void
	 */
	public function test_malformed_cache_is_discarded_and_recomputed(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::FUTURE );
		$order->update_meta_data( EstimatedDelivery::META_KEY, 'not an array' );

		$range = $this->service()->for_order( $order );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $range );
	}

	/**
	 * The public filter can override the computed range.
	 *
	 * @return void
	 */
	public function test_the_filter_can_override_the_range(): void {
		$this->configure(
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$override = new EstimatedDeliveryRange(
			new \DateTimeImmutable( '2099-01-01', new \DateTimeZone( 'UTC' ) ),
			new \DateTimeImmutable( '2099-01-02', new \DateTimeZone( 'UTC' ) ),
			'overridden'
		);

		add_filter(
			'pph_estimated_delivery',
			static function () use ( $override ) {
				return $override;
			}
		);

		$order = $this->order( 'flat_rate', self::FUTURE );

		$this->assertSame( $override, $this->service()->for_order( $order ) );
	}

	/**
	 * A filter returning an invalid type is ignored rather than trusted.
	 *
	 * @return void
	 */
	public function test_an_invalid_filter_return_is_ignored(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		add_filter(
			'pph_estimated_delivery',
			static function () {
				return 'not a range';
			}
		);

		$order = $this->order( 'flat_rate', self::FUTURE );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $this->service()->for_order( $order ) );
	}

	/**
	 * A reversed min/max transit config is normalised rather than trusted.
	 *
	 * @return void
	 */
	public function test_a_reversed_transit_range_is_normalised(): void {
		$this->configure(
			array(
				EstimatedDelivery::HANDLING_SETTING => 0,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 5,
						'max' => 1,
					),
				),
			)
		);

		$order = $this->order( 'flat_rate', self::FUTURE );
		$range = $this->service()->for_order( $order );

		$this->assertInstanceOf( EstimatedDeliveryRange::class, $range );
		$this->assertLessThanOrEqual( $range->end, $range->start );
	}
}
