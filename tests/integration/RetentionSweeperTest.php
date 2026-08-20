<?php
/**
 * Retention sweep integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Support\Logger;

/**
 * The sweep deletes merchant data, so the tests that matter are the ones
 * asserting what it must *not* touch: open requests, recent history, and
 * anything at all while retention is off.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Requests\RetentionSweeper
 */
final class RetentionSweeperTest extends \WP_UnitTestCase {

	/**
	 * Sweeper under test.
	 *
	 * @var RetentionSweeper
	 */
	private RetentionSweeper $sweeper;

	/**
	 * Repository used to seed rows.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $repository;

	/**
	 * Creates the tables once, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		Schema::install();
	}

	/**
	 * Builds the collaborators and clears the settings.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'pph_settings' );

		$this->sweeper    = new RetentionSweeper( new Logger() );
		$this->repository = new RequestRepository();
	}

	/**
	 * Retention is off until a merchant chooses a window.
	 *
	 * @return void
	 */
	public function test_retention_is_off_by_default(): void {
		$this->assertSame( 0, $this->sweeper->retention_days() );
	}

	/**
	 * With retention off, closed history of any age survives.
	 *
	 * @return void
	 */
	public function test_nothing_is_deleted_while_retention_is_off(): void {
		$this->seed( Request::STATUS_COMPLETED, '2019-01-01 09:00:00' );

		$result = $this->sweeper->sweep();

		$this->assertSame( 0, $result['requests'] );
		$this->assertSame( 1, $this->repository->count() );
	}

	/**
	 * A configured window removes closed requests older than it.
	 *
	 * @return void
	 */
	public function test_a_configured_window_removes_old_closed_requests(): void {
		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 30 ) );

		$old    = $this->seed( Request::STATUS_COMPLETED, gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ) );
		$recent = $this->seed( Request::STATUS_COMPLETED, gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) );

		$result = $this->sweeper->sweep();

		$this->assertSame( 1, $result['requests'] );
		$this->assertNull( $this->repository->find( $old ) );
		$this->assertNotNull( $this->repository->find( $recent ) );
	}

	/**
	 * A request still waiting on the merchant is never swept, however old.
	 *
	 * @return void
	 */
	public function test_an_open_request_is_never_swept(): void {
		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 1 ) );

		$pending = $this->seed( Request::STATUS_PENDING, '2019-01-01 09:00:00' );

		$this->sweeper->sweep();

		$this->assertNotNull( $this->repository->find( $pending ) );
	}

	/**
	 * Deleting a request takes its item rows with it.
	 *
	 * @return void
	 */
	public function test_item_rows_are_cascaded(): void {
		global $wpdb;

		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 1 ) );

		$id = $this->seed( Request::STATUS_APPROVED, '2019-01-01 09:00:00' );

		$wpdb->insert(
			Schema::request_items_table(),
			array(
				'request_id'    => $id,
				'order_item_id' => 5,
				'product_id'    => 9,
				'variation_id'  => 0,
				'quantity'      => 1,
			),
			array( '%d', '%d', '%d', '%d', '%d' )
		);

		$this->sweeper->sweep();

		$this->assertSame( 0, $this->count_items( $id ) );
	}

	/**
	 * Item rows whose request is gone are cleaned up even with retention off.
	 *
	 * @return void
	 */
	public function test_orphaned_item_rows_are_removed(): void {
		global $wpdb;

		$wpdb->insert(
			Schema::request_items_table(),
			array(
				'request_id'    => 999999,
				'order_item_id' => 5,
				'product_id'    => 9,
				'variation_id'  => 0,
				'quantity'      => 1,
			),
			array( '%d', '%d', '%d', '%d', '%d' )
		);

		$result = $this->sweeper->sweep();

		$this->assertSame( 1, $result['orphaned_items'] );
		$this->assertSame( 0, $this->count_items( 999999 ) );
	}

	/**
	 * A dry run reports without deleting.
	 *
	 * @return void
	 */
	public function test_a_dry_run_deletes_nothing(): void {
		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 1 ) );

		$id     = $this->seed( Request::STATUS_COMPLETED, '2019-01-01 09:00:00' );
		$result = $this->sweeper->sweep( 1, true );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['requests'] );
		$this->assertNotNull( $this->repository->find( $id ) );
	}

	/**
	 * Sweeping twice is safe and the second pass finds nothing left.
	 *
	 * @return void
	 */
	public function test_sweeping_is_idempotent(): void {
		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 1 ) );

		$this->seed( Request::STATUS_COMPLETED, '2019-01-01 09:00:00' );

		$this->assertSame( 1, $this->sweeper->sweep()['requests'] );
		$this->assertSame( 0, $this->sweeper->sweep()['requests'] );
		$this->assertSame( 0, $this->repository->count() );
	}

	/**
	 * A pass is bounded: one batch cannot delete more than the batch size.
	 *
	 * @return void
	 */
	public function test_a_pass_is_bounded_by_the_batch_size(): void {
		update_option( 'pph_settings', array( RetentionSweeper::RETENTION_SETTING => 1 ) );

		$result = $this->sweeper->sweep( 1 );

		$this->assertLessThanOrEqual( RetentionSweeper::BATCH_SIZE, $result['requests'] );
	}

	/**
	 * The window can be set by filter, and is clamped.
	 *
	 * @return void
	 */
	public function test_the_window_is_filterable_and_clamped(): void {
		add_filter(
			'pph_request_retention_days',
			static function (): int {
				return 99999;
			}
		);

		$this->assertSame( RetentionSweeper::MAX_RETENTION_DAYS, $this->sweeper->retention_days() );

		remove_all_filters( 'pph_request_retention_days' );

		add_filter(
			'pph_request_retention_days',
			static function (): int {
				return -30;
			}
		);

		$this->assertSame( 0, $this->sweeper->retention_days() );
	}

	/**
	 * Expired rate-limit transients are removed; live ones are not.
	 *
	 * @return void
	 */
	public function test_expired_transients_are_removed(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transients are not in the options table on this install.' );
		}

		set_transient( 'pph_0_expired_counter', 3, 1 );
		set_transient( 'pph_0_live_counter', 3, HOUR_IN_SECONDS );

		// Age the first one past its window without waiting for it.
		update_option( '_transient_timeout_pph_0_expired_counter', time() - 60, false );

		$result = $this->sweeper->sweep();

		$this->assertSame( 1, $result['expired_transients'] );
		$this->assertSame( 3, get_transient( 'pph_0_live_counter' ) );
	}

	/**
	 * Inserts a request with a chosen status and age.
	 *
	 * @param string $status     Request status.
	 * @param string $created_at UTC creation datetime.
	 * @return int
	 */
	private function seed( string $status, string $created_at ): int {
		return $this->repository->create(
			array(
				'order_id'   => 2000 + wp_rand( 1, 8000 ),
				'type'       => Request::TYPE_CANCELLATION,
				'source'     => Request::SOURCE_ACCOUNT,
				'status'     => $status,
				'created_at' => $created_at,
			)
		);
	}

	/**
	 * Counts item rows for a request.
	 *
	 * @param int $request_id Request id.
	 * @return int
	 */
	private function count_items( int $request_id ): int {
		global $wpdb;

		$sql = sprintf( 'SELECT COUNT(*) FROM %s WHERE request_id = %%d', Schema::request_items_table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table assertion in a test; the id is prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $request_id ) );
	}
}
