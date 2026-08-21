<?php
/**
 * Timeline backfill integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\CLI\BackfillCommand;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\TransitionRecorder;

require_once dirname( __DIR__ ) . '/stubs/wp-cli.php';

/**
 * Covers what the backfill may derive, what it must leave alone, and its
 * behaviour when interrupted.
 *
 * @since 0.3.0
 *
 * @covers \PostPurchaseHub\CLI\BackfillCommand
 */
final class BackfillCommandTest extends \WP_UnitTestCase {

	/**
	 * Resets the captured CLI output and the stored cursor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();
		delete_option( BackfillCommand::CURSOR_OPTION );
	}

	/**
	 * Builds the command over the plugin's own services.
	 *
	 * @return BackfillCommand
	 */
	private function command(): BackfillCommand {
		return new BackfillCommand(
			Plugin::instance()->transition_recorder(),
			Plugin::instance()->stage_map()
		);
	}

	/**
	 * Creates an order with dates but no recorded timeline, as a store that
	 * installed the plugin today would have.
	 *
	 * @param string      $status    Final status.
	 * @param string|null $paid      Payment date, UTC.
	 * @param string|null $completed Completion date, UTC.
	 * @return \WC_Order
	 */
	private function historical_order( string $status, ?string $paid = null, ?string $completed = null ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( $status );
		$order->set_date_created( '2026-03-01 09:00:00' );

		if ( null !== $paid ) {
			$order->set_date_paid( $paid );
		}

		if ( null !== $completed ) {
			$order->set_date_completed( $completed );
		}

		$order->save();

		$order->delete_meta_data( TransitionRecorder::META_KEY );
		$order->save();

		return $order;
	}

	/**
	 * The recorded entries on an order, read back from storage.
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
	 * Each date WooCommerce stores becomes one entry, oldest first.
	 *
	 * @return void
	 */
	public function test_it_derives_one_entry_per_stored_date(): void {
		$order = $this->historical_order( 'completed', '2026-03-02 10:00:00', '2026-03-04 11:00:00' );

		( $this->command() )( array(), array() );

		$entries = $this->stored_entries( $order->get_id() );

		$this->assertSame(
			array( StageMap::PLACED, StageMap::CONFIRMED, StageMap::DELIVERED ),
			array_column( $entries, 'stage' )
		);
		$this->assertSame( '2026-03-01 09:00:00', $entries[0]['timestamp_utc'] );
		$this->assertSame( '2026-03-04 11:00:00', $entries[2]['timestamp_utc'] );
	}

	/**
	 * An order with only a creation date gets only that.
	 *
	 * @return void
	 */
	public function test_it_derives_nothing_it_cannot_defend(): void {
		$order = $this->historical_order( 'pending' );

		( $this->command() )( array(), array() );

		$this->assertSame( array( StageMap::PLACED ), array_column( $this->stored_entries( $order->get_id() ), 'stage' ) );
	}

	/**
	 * A second run changes nothing, which is what makes interrupting it safe.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$order = $this->historical_order( 'completed', '2026-03-02 10:00:00', '2026-03-04 11:00:00' );

		( $this->command() )( array(), array() );

		$first = $this->stored_entries( $order->get_id() );

		( $this->command() )( array(), array( 'reset' => true ) );

		$this->assertSame( $first, $this->stored_entries( $order->get_id() ) );
	}

	/**
	 * Recorded history outranks a derived date and is never overwritten.
	 *
	 * @return void
	 */
	public function test_it_never_overwrites_a_recorded_transition(): void {
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_date_created( '2026-03-01 09:00:00' );
		$order->save();

		$order->set_status( 'processing' );
		$order->save();

		$order->set_date_paid( '2026-03-02 10:00:00' );
		$order->save();

		$recorded  = $this->stored_entries( $order->get_id() );
		$confirmed = '';

		foreach ( $recorded as $entry ) {
			if ( StageMap::CONFIRMED === $entry['stage'] ) {
				$confirmed = $entry['timestamp_utc'];
			}
		}

		$this->assertNotSame( '', $confirmed );

		( $this->command() )( array(), array() );

		foreach ( $this->stored_entries( $order->get_id() ) as $entry ) {
			if ( StageMap::CONFIRMED === $entry['stage'] ) {
				$this->assertSame( $confirmed, $entry['timestamp_utc'] );
			}
		}
	}

	/**
	 * A dry run reports and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$order = $this->historical_order( 'completed', '2026-03-02 10:00:00', '2026-03-04 11:00:00' );

		( $this->command() )( array(), array( 'dry-run' => true ) );

		$this->assertSame( array(), $this->stored_entries( $order->get_id() ) );
		$this->assertFalse( get_option( BackfillCommand::CURSOR_OPTION ) );
		$this->assertContains( 'log: Entries written: 3', \WP_CLI::$output );
	}

	/**
	 * Stopping at the limit stores a cursor the next run continues from.
	 *
	 * @return void
	 */
	public function test_it_resumes_from_its_cursor(): void {
		$first  = $this->historical_order( 'completed', '2026-03-02 10:00:00', '2026-03-04 11:00:00' );
		$second = $this->historical_order( 'completed', '2026-03-02 10:00:00', '2026-03-04 11:00:00' );

		( $this->command() )( array(), array( 'limit' => '1' ) );

		$this->assertNotEmpty( $this->stored_entries( $first->get_id() ) );
		$this->assertSame( array(), $this->stored_entries( $second->get_id() ) );

		$cursor = get_option( BackfillCommand::CURSOR_OPTION );

		$this->assertIsArray( $cursor );
		$this->assertSame( $first->get_id(), $cursor['last_id'] );

		( $this->command() )( array(), array() );

		$this->assertNotEmpty( $this->stored_entries( $second->get_id() ) );
		$this->assertFalse( get_option( BackfillCommand::CURSOR_OPTION ) );
	}

	/**
	 * Finishing a run clears the cursor so the next one starts clean.
	 *
	 * @return void
	 */
	public function test_a_completed_run_clears_the_cursor(): void {
		$this->historical_order( 'pending' );

		( $this->command() )( array(), array() );

		$this->assertFalse( get_option( BackfillCommand::CURSOR_OPTION ) );
		$this->assertContains( 'success: Backfill complete.', \WP_CLI::$output );
	}
}
