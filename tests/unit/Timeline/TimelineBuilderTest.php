<?php
/**
 * Timeline builder unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\Timeline;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TimelineStage;
use PostPurchaseHub\Timeline\TransitionRecorder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers each status path, branch states and historical degradation.
 *
 * @since 0.3.0
 *
 * @covers \PostPurchaseHub\Timeline\TimelineBuilder
 * @covers \PostPurchaseHub\Timeline\Timeline
 * @covers \PostPurchaseHub\Timeline\TimelineStage
 */
final class TimelineBuilderTest extends TestCase {

	/**
	 * Recorder used to seed order meta.
	 *
	 * @var TransitionRecorder
	 */
	private TransitionRecorder $recorder;

	/**
	 * Builder under test.
	 *
	 * @var TimelineBuilder
	 */
	private TimelineBuilder $builder;

	/**
	 * Builds the collaborators over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$stages         = new StageMap( new StatusDetector( new Cache() ) );
		$this->recorder = new TransitionRecorder( $stages, new Logger() );
		$this->builder  = new TimelineBuilder( $stages, $this->recorder );
	}

	/**
	 * Builds an order with a creation date.
	 *
	 * @param string      $status  Unprefixed status.
	 * @param string|null $created UTC creation time, or null for an order without one.
	 * @return \WC_Order
	 */
	private function order( string $status, ?string $created = '2026-03-01 09:00:00' ): \WC_Order {
		$order = new \WC_Order( 42, $status );

		if ( null !== $created ) {
			$order->set_date( 'created', new \WC_DateTime( $created, new \DateTimeZone( 'UTC' ) ) );
		}

		return $order;
	}

	/**
	 * Reads the states of the progress stages, keyed by stage.
	 *
	 * @param Timeline $timeline Built timeline.
	 * @return array<string, string>
	 */
	private function states( Timeline $timeline ): array {
		$states = array();

		foreach ( $timeline->stages as $stage ) {
			$states[ $stage->key ] = $stage->state;
		}

		return $states;
	}

	/**
	 * A brand-new order sits on Placed, dated from the order itself.
	 *
	 * WooCommerce fires no status-changed event for an order's first status, so
	 * without the creation date this order would have no timeline at all.
	 *
	 * @return void
	 */
	public function test_a_new_order_is_current_at_placed(): void {
		$timeline = $this->builder->build( $this->order( 'pending' ) );

		$this->assertSame(
			array(
				'placed'           => TimelineStage::STATE_CURRENT,
				'confirmed'        => TimelineStage::STATE_PENDING,
				'packed'           => TimelineStage::STATE_PENDING,
				'shipped'          => TimelineStage::STATE_PENDING,
				'out_for_delivery' => TimelineStage::STATE_PENDING,
				'delivered'        => TimelineStage::STATE_PENDING,
			),
			$this->states( $timeline )
		);

		$this->assertSame( '2026-03-01 09:00:00', $timeline->stages[0]->timestamp );
		$this->assertFalse( $timeline->historical );
	}

	/**
	 * The full lifecycle marks everything behind the order complete.
	 *
	 * @return void
	 */
	public function test_the_full_lifecycle_renders_at_every_step(): void {
		$order = $this->order( 'pending' );

		$order->set_status( 'processing' );
		$this->recorder->append( $order, 'processing', '2026-03-01 10:00:00' );

		$timeline = $this->builder->build( $order );

		$this->assertSame( TimelineStage::STATE_COMPLETE, $this->states( $timeline )['placed'] );
		$this->assertSame( TimelineStage::STATE_CURRENT, $this->states( $timeline )['confirmed'] );

		$order->set_status( 'completed' );
		$this->recorder->append( $order, 'completed', '2026-03-04 10:00:00' );

		$timeline = $this->builder->build( $order );

		$this->assertSame(
			array(
				'placed'           => TimelineStage::STATE_COMPLETE,
				'confirmed'        => TimelineStage::STATE_COMPLETE,
				'packed'           => TimelineStage::STATE_COMPLETE,
				'shipped'          => TimelineStage::STATE_COMPLETE,
				'out_for_delivery' => TimelineStage::STATE_COMPLETE,
				'delivered'        => TimelineStage::STATE_CURRENT,
			),
			$this->states( $timeline )
		);

		$this->assertSame( '2026-03-04 10:00:00', $timeline->current()->timestamp );
		$this->assertNull( $timeline->stages[2]->timestamp );
	}

	/**
	 * A cancelled order leaves the progress line for its branch state.
	 *
	 * @return void
	 */
	public function test_a_cancelled_order_reports_a_branch_state(): void {
		$order = $this->order( 'processing' );
		$this->recorder->append( $order, 'processing', '2026-03-01 10:00:00' );

		$order->set_status( 'cancelled' );
		$this->recorder->append( $order, 'cancelled', '2026-03-02 10:00:00' );

		$timeline = $this->builder->build( $order );

		$this->assertTrue( $timeline->has_branched() );
		$this->assertSame( StageMap::CANCELLED, $timeline->branch->key );
		$this->assertSame( '2026-03-02 10:00:00', $timeline->branch->timestamp );
		$this->assertSame( $timeline->branch, $timeline->current() );

		// No progress stage may be current while the order sits on a branch.
		$this->assertNotContains( TimelineStage::STATE_CURRENT, $this->states( $timeline ) );
		$this->assertSame( TimelineStage::STATE_COMPLETE, $this->states( $timeline )['confirmed'] );
		$this->assertSame( TimelineStage::STATE_PENDING, $this->states( $timeline )['packed'] );
	}

	/**
	 * Each branch status produces its own branch state.
	 *
	 * @dataProvider branch_statuses
	 *
	 * @param string $status Order status.
	 * @param string $stage  Expected branch key.
	 * @return void
	 */
	public function test_every_branch_status_branches( string $status, string $stage ): void {
		$timeline = $this->builder->build( $this->order( $status ) );

		$this->assertTrue( $timeline->has_branched() );
		$this->assertSame( $stage, $timeline->branch->key );
	}

	/**
	 * The branch statuses and the stages they land on.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function branch_statuses(): array {
		return array(
			'on-hold'   => array( 'on-hold', StageMap::ON_HOLD ),
			'cancelled' => array( 'cancelled', StageMap::CANCELLED ),
			'refunded'  => array( 'refunded', StageMap::REFUNDED ),
			'failed'    => array( 'failed', StageMap::FAILED ),
		);
	}

	/**
	 * An order that returns from a branch is back on the progress line.
	 *
	 * @return void
	 */
	public function test_an_order_that_leaves_a_branch_is_no_longer_branched(): void {
		$order = $this->order( 'on-hold' );
		$this->recorder->append( $order, 'on-hold', '2026-03-01 10:00:00' );

		$order->set_status( 'processing' );
		$this->recorder->append( $order, 'processing', '2026-03-02 10:00:00' );

		$timeline = $this->builder->build( $order );

		$this->assertFalse( $timeline->has_branched() );
		$this->assertSame( StageMap::CONFIRMED, $timeline->current()->key );
	}

	/**
	 * A pre-activation order still renders, with no transition timestamps.
	 *
	 * @return void
	 */
	public function test_a_historical_order_renders_stages_without_timestamps(): void {
		$timeline = $this->builder->build( $this->order( 'completed' ) );

		$this->assertTrue( $timeline->historical );
		$this->assertSame( TimelineStage::STATE_CURRENT, $this->states( $timeline )['delivered'] );
		$this->assertNull( $timeline->current()->timestamp );

		foreach ( $timeline->stages as $stage ) {
			if ( StageMap::PLACED !== $stage->key ) {
				$this->assertNull( $stage->timestamp, $stage->key . ' must carry no invented timestamp' );
			}
		}
	}

	/**
	 * A historical order on a branch state is historical too.
	 *
	 * @return void
	 */
	public function test_a_historical_branch_order_is_historical(): void {
		$timeline = $this->builder->build( $this->order( 'refunded' ) );

		$this->assertTrue( $timeline->historical );
		$this->assertNull( $timeline->branch->timestamp );
	}

	/**
	 * An order with no creation date at all still builds.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_creation_date_still_builds(): void {
		$timeline = $this->builder->build( $this->order( 'pending', null ) );

		$this->assertNull( $timeline->stages[0]->timestamp );
		$this->assertTrue( $timeline->historical );
		$this->assertFalse( $timeline->has_timestamps() );
	}

	/**
	 * The creation date is reported in UTC whatever the order carries.
	 *
	 * @return void
	 */
	public function test_the_creation_date_is_converted_to_utc(): void {
		$order = new \WC_Order( 42, 'pending' );
		$order->set_date( 'created', new \WC_DateTime( '2026-03-01 09:00:00', new \DateTimeZone( 'Asia/Kathmandu' ) ) );

		$timeline = $this->builder->build( $order );

		$this->assertSame( '2026-03-01 03:15:00', $timeline->stages[0]->timestamp );
	}

	/**
	 * Corrupt meta degrades to the historical rendering instead of failing.
	 *
	 * @return void
	 */
	public function test_corrupt_meta_falls_back_instead_of_fataling(): void {
		$order = $this->order( 'completed' );
		$order->update_meta_data( TransitionRecorder::META_KEY, 'corrupted' );

		$timeline = $this->builder->build( $order );

		$this->assertTrue( $timeline->historical );
		$this->assertCount( 6, $timeline->stages );
		$this->assertSame( TimelineStage::STATE_CURRENT, $this->states( $timeline )['delivered'] );
	}

	/**
	 * An unmapped status renders the shape with nothing claimed about progress.
	 *
	 * @return void
	 */
	public function test_an_unmapped_status_reports_no_current_stage(): void {
		$timeline = $this->builder->build( $this->order( 'checkout-draft' ) );

		$this->assertFalse( $timeline->has_branched() );
		$this->assertTrue( $timeline->historical );
		$this->assertSame( TimelineStage::STATE_CURRENT, $this->states( $timeline )['placed'] );
	}

	/**
	 * Building reads the order and never writes to it.
	 *
	 * @return void
	 */
	public function test_building_never_saves_the_order(): void {
		$order = $this->order( 'processing' );

		$this->builder->build( $order );

		$this->assertSame( 0, $order->saves );
	}
}
