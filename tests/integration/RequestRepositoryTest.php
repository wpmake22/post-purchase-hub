<?php
/**
 * Repository integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestQuery;
use PostPurchaseHub\Requests\RequestRepository;

/**
 * Exercises the repository against a real database, including the pagination
 * boundaries and the injection attempts that must never reach SQL.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Requests\RequestRepository
 */
final class RequestRepositoryTest extends \WP_UnitTestCase {

	/**
	 * Repository under test.
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
	 * Builds the repository.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new RequestRepository();
	}

	/**
	 * A created request reads back exactly as stored.
	 *
	 * @return void
	 */
	public function test_a_created_request_reads_back(): void {
		$id = $this->repository->create(
			array(
				'order_id'            => 4321,
				'customer_id'         => 7,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'type'                => Request::TYPE_CANCELLATION,
				'source'              => Request::SOURCE_ACCOUNT,
				'reason_code'         => 'changed_mind',
				'customer_note'       => 'Ordered the wrong size.',
				'amount'              => '24.99',
				'currency'            => 'gbp',
			)
		);

		$this->assertGreaterThan( 0, $id );

		$request = $this->repository->find( $id );

		$this->assertInstanceOf( Request::class, $request );
		$this->assertSame( 4321, $request->order_id );
		$this->assertSame( 7, $request->customer_id );
		$this->assertSame( Request::STATUS_PENDING, $request->status );
		$this->assertSame( '24.9900', $request->amount );
		$this->assertSame( 'GBP', $request->currency );
		$this->assertNull( $request->resolved_at );
		$this->assertNotSame( '', $request->created_at );
	}

	/**
	 * A missing or nonsensical id returns null rather than throwing.
	 *
	 * @return void
	 */
	public function test_a_missing_request_is_null(): void {
		$this->assertNull( $this->repository->find( 987654 ) );
		$this->assertNull( $this->repository->find( 0 ) );
		$this->assertNull( $this->repository->find( -1 ) );
	}

	/**
	 * A request cannot be created without the columns it is identified by.
	 *
	 * @dataProvider incomplete_provider
	 *
	 * @param array<string, mixed> $data Incomplete payload.
	 * @return void
	 */
	public function test_an_incomplete_request_is_rejected( array $data ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->repository->create( $data );
	}

	/**
	 * Payloads that must be refused.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function incomplete_provider(): array {
		return array(
			'no order'  => array(
				array(
					'type'   => Request::TYPE_HELP,
					'source' => Request::SOURCE_ADMIN,
				),
			),
			'no type'   => array(
				array(
					'order_id' => 1,
					'source'   => Request::SOURCE_ADMIN,
				),
			),
			'no source' => array(
				array(
					'order_id' => 1,
					'type'     => Request::TYPE_HELP,
				),
			),
			'bad type'  => array(
				array(
					'order_id' => 1,
					'type'     => 'refund',
					'source'   => Request::SOURCE_ADMIN,
				),
			),
		);
	}

	/**
	 * An update writes the changed columns and moves updated_at.
	 *
	 * @return void
	 */
	public function test_an_update_writes_the_changes(): void {
		$id = $this->seed( array( 'order_id' => 11 ) );

		$this->assertTrue(
			$this->repository->update(
				$id,
				array(
					'status'      => Request::STATUS_APPROVED,
					'admin_note'  => 'Approved by phone.',
					'resolved_at' => '2026-08-20 12:00:00',
					'resolved_by' => 3,
				)
			)
		);

		$request = $this->repository->find( $id );

		$this->assertSame( Request::STATUS_APPROVED, $request->status );
		$this->assertSame( 'Approved by phone.', $request->admin_note );
		$this->assertSame( '2026-08-20 12:00:00', $request->resolved_at );
		$this->assertSame( 3, $request->resolved_by );
	}

	/**
	 * A request cannot be moved to another order.
	 *
	 * @dataProvider immutable_provider
	 *
	 * @param string $column Immutable column.
	 * @return void
	 */
	public function test_immutable_columns_are_refused( string $column ): void {
		$id = $this->seed();

		$this->expectException( \InvalidArgumentException::class );

		$this->repository->update( $id, array( $column => 99 ) );
	}

	/**
	 * Columns an update may not touch.
	 *
	 * @return array<string, array{string}>
	 */
	public static function immutable_provider(): array {
		return array(
			'id'         => array( 'id' ),
			'order_id'   => array( 'order_id' ),
			'created_at' => array( 'created_at' ),
		);
	}

	/**
	 * Updating nothing, or a row that does not exist, reports no change.
	 *
	 * @return void
	 */
	public function test_an_empty_update_changes_nothing(): void {
		$this->assertFalse( $this->repository->update( $this->seed(), array() ) );
		$this->assertFalse( $this->repository->update( 987654, array( 'status' => Request::STATUS_DECLINED ) ) );
	}

	/**
	 * An order's requests come back newest first.
	 *
	 * @return void
	 */
	public function test_an_orders_requests_come_back_newest_first(): void {
		$this->seed(
			array(
				'order_id'   => 55,
				'created_at' => '2026-08-01 09:00:00',
			)
		);
		$this->seed(
			array(
				'order_id'   => 55,
				'created_at' => '2026-08-05 09:00:00',
			)
		);
		$this->seed( array( 'order_id' => 56 ) );

		$found = $this->repository->find_by_order( 55 );

		$this->assertCount( 2, $found );
		$this->assertSame( '2026-08-05 09:00:00', $found[0]->created_at );
		$this->assertSame( array(), $this->repository->find_by_order( 0 ) );
	}

	/**
	 * Filters narrow the result set, and count() agrees with query().
	 *
	 * @return void
	 */
	public function test_filters_narrow_the_results(): void {
		$this->seed(
			array(
				'order_id' => 61,
				'status'   => Request::STATUS_PENDING,
				'type'     => Request::TYPE_CANCELLATION,
			)
		);
		$this->seed(
			array(
				'order_id' => 62,
				'status'   => Request::STATUS_APPROVED,
				'type'     => Request::TYPE_CANCELLATION,
			)
		);
		$this->seed(
			array(
				'order_id' => 63,
				'status'   => Request::STATUS_PENDING,
				'type'     => Request::TYPE_HELP,
			)
		);

		$this->assertCount( 2, $this->repository->query( array( 'status' => Request::STATUS_PENDING ) ) );
		$this->assertSame( 2, $this->repository->count( array( 'status' => Request::STATUS_PENDING ) ) );
		$this->assertSame( 3, $this->repository->count() );
		$this->assertSame(
			1,
			$this->repository->count(
				array(
					'status' => Request::STATUS_PENDING,
					'type'   => Request::TYPE_HELP,
				)
			)
		);
		$this->assertSame(
			2,
			$this->repository->count(
				array(
					'status' => array( Request::STATUS_PENDING, Request::STATUS_APPROVED ),
					'type'   => Request::TYPE_CANCELLATION,
				)
			)
		);
	}

	/**
	 * Pagination returns each row exactly once, including at the boundaries.
	 *
	 * @return void
	 */
	public function test_pagination_boundaries(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed(
				array(
					'order_id'   => 700 + $i,
					'created_at' => sprintf( '2026-08-%02d 09:00:00', $i ),
				)
			);
		}

		$first  = $this->repository->query( array(), 'created_at', 'ASC', 1, 2 );
		$second = $this->repository->query( array(), 'created_at', 'ASC', 2, 2 );
		$third  = $this->repository->query( array(), 'created_at', 'ASC', 3, 2 );
		$fourth = $this->repository->query( array(), 'created_at', 'ASC', 4, 2 );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );
		$this->assertCount( 1, $third );
		$this->assertCount( 0, $fourth );

		$ids = array_map(
			static function ( Request $request ): int {
				return $request->id;
			},
			array_merge( $first, $second, $third )
		);

		$this->assertCount( 5, array_unique( $ids ) );
		$this->assertSame( 701, $first[0]->order_id );
	}

	/**
	 * Rows sharing a sort value still page deterministically.
	 *
	 * @return void
	 */
	public function test_paging_is_stable_when_the_sort_value_repeats(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed(
				array(
					'order_id'   => 800 + $i,
					'created_at' => '2026-08-09 09:00:00',
				)
			);
		}

		$page_one = $this->repository->query( array(), 'created_at', 'ASC', 1, 2 );
		$page_two = $this->repository->query( array(), 'created_at', 'ASC', 2, 2 );

		$ids = array_map(
			static function ( Request $request ): int {
				return $request->id;
			},
			array_merge( $page_one, $page_two )
		);

		$this->assertCount( 4, array_unique( $ids ) );
	}

	/**
	 * A caller cannot ask for more rows than the ceiling allows.
	 *
	 * @return void
	 */
	public function test_per_page_is_capped(): void {
		$this->seed();

		$this->assertLessThanOrEqual(
			RequestQuery::MAX_PER_PAGE,
			count( $this->repository->query( array(), 'id', 'ASC', 1, 100000 ) )
		);
	}

	/**
	 * An unexpected orderby value is refused and the data is untouched.
	 *
	 * @return void
	 */
	public function test_an_unexpected_orderby_never_reaches_sql(): void {
		$this->seed();

		try {
			$this->repository->query( array(), 'id; DROP TABLE ' . Schema::requests_table(), 'ASC' );
			$this->fail( 'An unexpected orderby column should have been refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
			$this->assertSame( 1, $this->repository->count() );
		}
	}

	/**
	 * Injection through a filter value returns no rows and breaks nothing.
	 *
	 * @dataProvider payload_provider
	 *
	 * @param string $payload Attempted injection.
	 * @return void
	 */
	public function test_injection_through_a_filter_is_inert( string $payload ): void {
		$this->seed();

		try {
			$found = $this->repository->query( array( 'status' => $payload ) );
			$this->assertSame( array(), $found );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true, 'Rejecting the value outright is also acceptable.' );
		}

		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
		$this->assertSame( 1, $this->repository->count() );
	}

	/**
	 * Injection payloads.
	 *
	 * @return array<string, array{string}>
	 */
	public static function payload_provider(): array {
		return array(
			'or true'   => array( "' OR '1'='1" ),
			'drop'      => array( "x'; DROP TABLE wp_posts; --" ),
			'union'     => array( "' UNION SELECT user_pass FROM wp_users --" ),
			'sleep'     => array( "' OR SLEEP(3) --" ),
			'format'    => array( '%s' ),
			'backslash' => array( 'x\\' ),
		);
	}

	/**
	 * An unknown filter is refused rather than dropped.
	 *
	 * @return void
	 */
	public function test_an_unknown_filter_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->repository->count( array( 'customer_email' => 'buyer@example.com' ) );
	}

	/**
	 * Lists name their columns, so adding one later cannot widen what the admin
	 * queue pulls into memory.
	 *
	 * @return void
	 */
	public function test_queries_do_not_select_star(): void {
		global $wpdb;

		$this->seed();
		$this->repository->query();

		$this->assertStringNotContainsString( 'SELECT *', (string) $wpdb->last_query );
		$this->assertStringContainsString( 'customer_email_hash', (string) $wpdb->last_query );
	}

	/**
	 * Every statement the repository issues is prepared, with no interpolated
	 * value left in the query text.
	 *
	 * @return void
	 */
	public function test_statements_are_prepared(): void {
		global $wpdb;

		$this->seed( array( 'order_id' => 909 ) );
		$this->repository->query( array( 'order_id' => 909 ) );

		$this->assertStringNotContainsString( '%d', (string) $wpdb->last_query );
		$this->assertStringContainsString( '909', (string) $wpdb->last_query );
	}

	/**
	 * Inserts a request with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return int
	 */
	private function seed( array $overrides = array() ): int {
		return $this->repository->create(
			array_merge(
				array(
					'order_id' => 1000 + wp_rand( 1, 8000 ),
					'type'     => Request::TYPE_CANCELLATION,
					'source'   => Request::SOURCE_ACCOUNT,
				),
				$overrides
			)
		);
	}
}
