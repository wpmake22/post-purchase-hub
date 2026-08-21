<?php
/**
 * Transition recorder unit tests.
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
use PostPurchaseHub\Timeline\TransitionRecorder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the forward-only rule, the entry cap and malformed meta.
 *
 * @since 0.3.0
 *
 * @covers \PostPurchaseHub\Timeline\TransitionRecorder
 */
final class TransitionRecorderTest extends TestCase {

	/**
	 * Recorder under test.
	 *
	 * @var TransitionRecorder
	 */
	private TransitionRecorder $recorder;

	/**
	 * Builds the recorder over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->recorder = new TransitionRecorder( new StageMap( new StatusDetector( new Cache() ) ), new Logger() );
	}

	/**
	 * Reads the timeline meta off an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, string>>
	 */
	private function meta( \WC_Order $order ): array {
		$raw = $order->get_meta( TransitionRecorder::META_KEY, true );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * A recorded transition stores the status, its stage and a UTC timestamp.
	 *
	 * @return void
	 */
	public function test_a_transition_is_recorded_with_its_stage(): void {
		$order = new \WC_Order( 7, 'processing' );

		$this->assertTrue( $this->recorder->append( $order, 'processing', '2026-03-01 10:00:00' ) );

		$this->assertSame(
			array(
				array(
					'status'        => 'processing',
					'stage'         => StageMap::CONFIRMED,
					'timestamp_utc' => '2026-03-01 10:00:00',
				),
			),
			$this->meta( $order )
		);
	}

	/**
	 * The `wc-` prefix never reaches storage.
	 *
	 * @return void
	 */
	public function test_the_status_prefix_is_normalised(): void {
		$order = new \WC_Order( 7, 'processing' );

		$this->recorder->append( $order, 'wc-processing', '2026-03-01 10:00:00' );

		$this->assertSame( 'processing', $this->meta( $order )[0]['status'] );
	}

	/**
	 * Returning to a stage keeps the first timestamp.
	 *
	 * @return void
	 */
	public function test_a_repeated_stage_is_never_rewritten(): void {
		$order = new \WC_Order( 7, 'processing' );

		$this->recorder->append( $order, 'processing', '2026-03-01 10:00:00' );
		$this->recorder->append( $order, 'on-hold', '2026-03-01 11:00:00' );

		$this->assertFalse( $this->recorder->append( $order, 'processing', '2026-03-01 12:00:00' ) );

		$entries = $this->meta( $order );

		$this->assertCount( 2, $entries );
		$this->assertSame( '2026-03-01 10:00:00', $entries[0]['timestamp_utc'] );
	}

	/**
	 * An entry added out of order is filed in chronological position.
	 *
	 * @return void
	 */
	public function test_entries_are_kept_in_chronological_order(): void {
		$order = new \WC_Order( 7, 'completed' );

		$this->recorder->append( $order, 'completed', '2026-03-05 09:00:00' );
		$this->recorder->append( $order, 'pending', '2026-03-01 09:00:00' );

		$this->assertSame(
			array( StageMap::PLACED, StageMap::DELIVERED ),
			array_column( $this->meta( $order ), 'stage' )
		);
	}

	/**
	 * An unmapped status records nothing at all.
	 *
	 * @return void
	 */
	public function test_an_unmapped_status_is_not_recorded(): void {
		$order = new \WC_Order( 7, 'checkout-draft' );

		$this->assertFalse( $this->recorder->append( $order, 'checkout-draft', '2026-03-01 10:00:00' ) );
		$this->assertSame( array(), $this->meta( $order ) );
	}

	/**
	 * The array stops at the cap, and branch states survive the trimming.
	 *
	 * @return void
	 */
	public function test_the_entry_cap_holds_and_spares_branch_states(): void {
		add_filter(
			'pph_timeline_stages',
			static function ( array $stages ): array {
				for ( $i = 0; $i < 12; $i++ ) {
					$stages[ 'extra_' . $i ] = 'Extra ' . $i;
				}

				return $stages;
			}
		);

		add_filter(
			'pph_status_stage_map',
			static function ( array $map ): array {
				for ( $i = 0; $i < 12; $i++ ) {
					$map[ 'custom-' . $i ] = 'extra_' . $i;
				}

				return $map;
			}
		);

		$recorder = new TransitionRecorder( new StageMap( new StatusDetector( new Cache() ) ), new Logger() );
		$order    = new \WC_Order( 7, 'cancelled' );

		$recorder->append( $order, 'cancelled', '2026-03-01 00:00:00' );

		for ( $i = 0; $i < 12; $i++ ) {
			$recorder->append( $order, 'custom-' . $i, sprintf( '2026-03-02 %02d:00:00', $i ) );
		}

		$entries = $this->meta( $order );

		$this->assertCount( TransitionRecorder::MAX_ENTRIES, $entries );
		$this->assertContains( StageMap::CANCELLED, array_column( $entries, 'stage' ) );
		$this->assertContains( 'extra_11', array_column( $entries, 'stage' ) );
		$this->assertNotContains( 'extra_0', array_column( $entries, 'stage' ) );
	}

	/**
	 * Meta that is not an array at all is discarded rather than fatal.
	 *
	 * @return void
	 */
	public function test_non_array_meta_is_discarded(): void {
		$order = new \WC_Order( 7, 'processing' );
		$order->update_meta_data( TransitionRecorder::META_KEY, 'corrupted' );

		$this->assertSame( array(), $this->recorder->read( $order ) );
	}

	/**
	 * Malformed entries are dropped and the well-formed ones survive.
	 *
	 * @return void
	 */
	public function test_malformed_entries_are_dropped_individually(): void {
		$order = new \WC_Order( 7, 'processing' );
		$order->update_meta_data(
			TransitionRecorder::META_KEY,
			array(
				'not an entry',
				array( 'stage' => 'confirmed' ),
				array(
					'status'        => 'processing',
					'stage'         => 'confirmed',
					'timestamp_utc' => '2026-03-01 10:00:00',
				),
				array(
					'stage'         => 'delivered',
					'timestamp_utc' => '2026-03-02 10:00:00',
				),
			)
		);

		$entries = $this->recorder->read( $order );

		$this->assertCount( 2, $entries );
		$this->assertSame( array( 'confirmed', 'delivered' ), array_column( $entries, 'stage' ) );
		$this->assertSame( '', $entries[1]['status'] );
	}

	/**
	 * The hook callback writes once and only once.
	 *
	 * @return void
	 */
	public function test_recording_saves_the_order_exactly_once(): void {
		$order = new \WC_Order( 7, 'processing' );

		$this->recorder->record( 7, 'pending', 'processing', $order );

		$this->assertSame( 1, $order->saves );
		$this->assertCount( 1, $this->meta( $order ) );

		$this->recorder->record( 7, 'on-hold', 'processing', $order );

		$this->assertSame( 1, $order->saves );
	}

	/**
	 * A hook fired without the order object falls back to a lookup.
	 *
	 * @return void
	 */
	public function test_recording_without_an_order_object(): void {
		$order                 = new \WC_Order( 7, 'processing' );
		FakeWordPress::$orders = array( 7 => $order );

		$this->recorder->record( 7, 'pending', 'processing' );

		$this->assertCount( 1, $this->meta( $order ) );
	}

	/**
	 * An id that resolves to no order is survivable: core turns anything thrown
	 * from this hook into an order note the merchant has to read.
	 *
	 * @doesNotPerformAssertions
	 *
	 * @return void
	 */
	public function test_recording_an_unknown_order_does_not_throw(): void {
		$this->recorder->record( 404, 'pending', 'processing' );
	}
}
