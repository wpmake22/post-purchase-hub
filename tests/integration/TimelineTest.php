<?php
/**
 * Timeline integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\Timeline;
use PostPurchaseHub\Timeline\TimelineStage;
use PostPurchaseHub\Timeline\TransitionRecorder;

/**
 * Exercises the timeline against real orders under both storage engines.
 *
 * @since 0.3.0
 *
 * @covers \PostPurchaseHub\Timeline\TransitionRecorder
 * @covers \PostPurchaseHub\Timeline\TimelineBuilder
 */
final class TimelineTest extends \WP_UnitTestCase {

	/**
	 * Creates the HPOS tables outside any test's transaction.
	 *
	 * The parity test drives both storage engines in one run, so both sets of
	 * tables have to exist regardless of which engine the suite booted with.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
	}

	/**
	 * Creates an order and puts it through a series of statuses.
	 *
	 * @param array<int, string> $statuses Statuses to move through, in order.
	 * @return \WC_Order
	 */
	private function order_through( array $statuses ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->save();

		foreach ( $statuses as $status ) {
			$order->set_status( $status );
			$order->save();
		}

		return $order;
	}

	/**
	 * The recorded transitions on an order, read back from storage.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, array<string, string>>
	 */
	private function stored_entries( int $order_id ): array {
		$fresh = wc_get_order( $order_id );

		$this->assertInstanceOf( \WC_Order::class, $fresh );

		$raw = $fresh->get_meta( TransitionRecorder::META_KEY, true );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * The stage states of a built timeline, keyed by stage.
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
	 * Every transition lands in the meta, in shape and in order.
	 *
	 * @return void
	 */
	public function test_each_transition_appends_one_entry(): void {
		$order = $this->order_through( array( 'processing', 'completed' ) );

		$entries = $this->stored_entries( $order->get_id() );

		$this->assertCount( 2, $entries );
		$this->assertSame( array( 'processing', 'completed' ), array_column( $entries, 'status' ) );
		$this->assertSame( array( StageMap::CONFIRMED, StageMap::DELIVERED ), array_column( $entries, 'stage' ) );

		foreach ( $entries as $entry ) {
			$this->assertSame( array( 'status', 'stage', 'timestamp_utc' ), array_keys( $entry ) );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['timestamp_utc'] );
		}
	}

	/**
	 * Returning to a status leaves the original timestamp alone.
	 *
	 * @return void
	 */
	public function test_a_repeated_transition_does_not_rewrite_history(): void {
		$order = $this->order_through( array( 'processing', 'on-hold' ) );

		$first = $this->stored_entries( $order->get_id() );

		$order->set_status( 'processing' );
		$order->save();

		$second = $this->stored_entries( $order->get_id() );

		$this->assertSame( $first, $second );
	}

	/**
	 * The stored array never grows past its cap.
	 *
	 * @return void
	 */
	public function test_the_stored_array_never_exceeds_the_cap(): void {
		$order = $this->order_through(
			array( 'processing', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled', 'pending', 'failed', 'processing' )
		);

		$this->assertLessThanOrEqual(
			TransitionRecorder::MAX_ENTRIES,
			count( $this->stored_entries( $order->get_id() ) )
		);
	}

	/**
	 * A live order's timeline reports where it is and when it got there.
	 *
	 * @return void
	 */
	public function test_a_recorded_order_builds_a_dated_timeline(): void {
		$order = $this->order_through( array( 'processing' ) );

		$timeline = Plugin::instance()->timeline_builder()->build( wc_get_order( $order->get_id() ) );

		$this->assertFalse( $timeline->historical );
		$this->assertSame( StageMap::CONFIRMED, $timeline->current()->key );
		$this->assertNotNull( $timeline->current()->timestamp );
		$this->assertSame( TimelineStage::STATE_COMPLETE, $this->states( $timeline )['placed'] );
	}

	/**
	 * An order that predates the plugin renders its stages and no dates.
	 *
	 * PHPUnit is configured to convert notices and warnings into failures, so
	 * this also covers "no notice" from the acceptance criteria.
	 *
	 * @return void
	 */
	public function test_a_pre_activation_order_renders_without_timestamps(): void {
		$order = new \WC_Order();
		$order->set_status( 'completed' );
		$order->save();

		// Exactly the state an order placed before activation is in: a status, and
		// no record of how it got there.
		$order->delete_meta_data( TransitionRecorder::META_KEY );
		$order->save();

		$timeline = Plugin::instance()->timeline_builder()->build( wc_get_order( $order->get_id() ) );

		$this->assertTrue( $timeline->historical );
		$this->assertCount( 6, $timeline->stages );
		$this->assertSame( TimelineStage::STATE_CURRENT, $this->states( $timeline )['delivered'] );
		$this->assertNull( $timeline->current()->timestamp );
	}

	/**
	 * Corrupt meta degrades to the historical rendering rather than fataling.
	 *
	 * @return void
	 */
	public function test_corrupt_meta_falls_back(): void {
		$order = $this->order_through( array( 'processing' ) );
		$order->update_meta_data( TransitionRecorder::META_KEY, 'not an array' );
		$order->save();

		$timeline = Plugin::instance()->timeline_builder()->build( wc_get_order( $order->get_id() ) );

		$this->assertTrue( $timeline->historical );
		$this->assertSame( StageMap::CONFIRMED, $timeline->current()->key );
	}

	/**
	 * A merchant's own order notes are not a data source and cannot become one.
	 *
	 * @return void
	 */
	public function test_order_notes_do_not_affect_the_timeline(): void {
		$order = $this->order_through( array( 'processing' ) );

		$order->add_order_note( 'Order status changed from Processing to Completed.' );

		$before = $this->stored_entries( $order->get_id() );

		$timeline = Plugin::instance()->timeline_builder()->build( wc_get_order( $order->get_id() ) );

		$this->assertSame( $before, $this->stored_entries( $order->get_id() ) );
		$this->assertSame( StageMap::CONFIRMED, $timeline->current()->key );
	}

	/**
	 * The same lifecycle produces the same timeline on HPOS and on post storage.
	 *
	 * Both engines are driven inside this one test rather than relying on the
	 * suite's two runs, because the failure this guards against — a code path
	 * that only works on the storage the developer happened to have on — is
	 * invisible when each engine is only ever seen alone.
	 *
	 * @return void
	 */
	public function test_hpos_and_post_storage_produce_identical_timelines(): void {
		$hpos   = $this->timeline_under_storage( true );
		$legacy = $this->timeline_under_storage( false );

		$this->assertSame( $legacy['engine'], 'posts' );
		$this->assertSame( $hpos['engine'], 'hpos' );
		$this->assertSame( $legacy['stages'], $hpos['stages'] );
		$this->assertSame( $legacy['entries'], $hpos['entries'] );
		$this->assertSame( $legacy['current'], $hpos['current'] );
	}

	/**
	 * Runs one order's lifecycle with a given storage engine forced on.
	 *
	 * The usage option is filtered rather than written: writing it triggers
	 * WooCommerce's own migration guards, and the data store is resolved through
	 * a live get_option() call on every order object, so filtering is enough.
	 *
	 * @param bool $hpos Whether to force HPOS.
	 * @return array{engine: string, stages: array<string, string>, entries: list<string>, current: string}
	 */
	private function timeline_under_storage( bool $hpos ): array {
		$option = CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION;
		$value  = $hpos ? 'yes' : 'no';

		$force = static function () use ( $value ): string {
			return $value;
		};

		add_filter( 'pre_option_' . $option, $force );

		try {
			$order = $this->order_through( array( 'processing', 'completed' ) );
			$fresh = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( \WC_Order::class, $fresh );

			$timeline = Plugin::instance()->timeline_builder()->build( $fresh );

			$entries = array();

			foreach ( $this->stored_entries( $order->get_id() ) as $entry ) {
				$entries[] = $entry['status'] . ':' . $entry['stage'];
			}

			return array(
				'engine'  => $fresh->get_data_store()->get_current_class_name() === 'WC_Order_Data_Store_CPT' ? 'posts' : 'hpos',
				'stages'  => $this->states( $timeline ),
				'entries' => $entries,
				'current' => (string) $timeline->current()->key,
			);
		} finally {
			remove_filter( 'pre_option_' . $option, $force );
		}
	}
}
