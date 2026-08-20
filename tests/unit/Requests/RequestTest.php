<?php
/**
 * Request model unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the model and the three vocabularies extensions can widen.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Requests\Request
 */
final class RequestTest extends TestCase {

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
	 * A row round-trips through the model unchanged.
	 *
	 * @return void
	 */
	public function test_a_row_round_trips(): void {
		$row = array(
			'id'                  => '7',
			'order_id'            => '123',
			'customer_id'         => '0',
			'customer_email_hash' => str_repeat( 'b', 64 ),
			'type'                => Request::TYPE_CANCELLATION,
			'status'              => Request::STATUS_PENDING,
			'reason_code'         => 'changed_mind',
			'customer_note'       => 'Please cancel.',
			'admin_note'          => null,
			'amount'              => '12.5000',
			'currency'            => 'GBP',
			'source'              => Request::SOURCE_GUEST_TOKEN,
			'created_at'          => '2026-08-20 10:00:00',
			'updated_at'          => '2026-08-20 10:00:00',
			'resolved_at'         => null,
			'resolved_by'         => null,
		);

		$request = Request::from_row( $row );

		$this->assertSame( 7, $request->id );
		$this->assertSame( 123, $request->order_id );
		$this->assertSame( 0, $request->customer_id );
		$this->assertSame( '12.5000', $request->amount );
		$this->assertNull( $request->admin_note );
		$this->assertNull( $request->resolved_by );
		$this->assertSame(
			array_merge(
				$row,
				array(
					'id'          => 7,
					'order_id'    => 123,
					'customer_id' => 0,
				)
			),
			$request->to_array()
		);
	}

	/**
	 * A money value stays a string, because a float cannot be reconciled.
	 *
	 * @return void
	 */
	public function test_money_is_not_a_float(): void {
		$request = Request::from_row( array( 'amount' => '19.9900' ) );

		$this->assertIsString( $request->amount );
		$this->assertSame( '19.9900', $request->amount );
	}

	/**
	 * A partial row does not fatal; missing columns take empty defaults.
	 *
	 * @return void
	 */
	public function test_a_partial_row_is_tolerated(): void {
		$request = Request::from_row( array( 'id' => 3 ) );

		$this->assertSame( 3, $request->id );
		$this->assertSame( '', $request->type );
		$this->assertNull( $request->resolved_at );
	}

	/**
	 * Only a pending request is open.
	 *
	 * @return void
	 */
	public function test_only_a_pending_request_is_open(): void {
		$this->assertTrue( Request::from_row( array( 'status' => Request::STATUS_PENDING ) )->is_open() );
		$this->assertFalse( Request::from_row( array( 'status' => Request::STATUS_APPROVED ) )->is_open() );
	}

	/**
	 * The default vocabularies match the specified columns.
	 *
	 * @return void
	 */
	public function test_the_default_vocabularies(): void {
		$this->assertSame( array( 'cancellation', 'return', 'help' ), Request::types() );
		$this->assertSame( array( 'pending', 'approved', 'declined', 'withdrawn', 'completed' ), Request::statuses() );
		$this->assertSame( array( 'account', 'guest_token', 'guest_lookup', 'admin' ), Request::sources() );
	}

	/**
	 * An extension can add a type without touching core.
	 *
	 * @return void
	 */
	public function test_a_filter_can_add_a_type(): void {
		add_filter(
			'pph_request_types',
			static function ( array $types ): array {
				$types[] = 'exchange';

				return $types;
			}
		);

		$this->assertContains( 'exchange', Request::types() );
	}

	/**
	 * A filter returning junk cannot widen what may reach the column.
	 *
	 * @dataProvider junk_provider
	 *
	 * @param mixed $junk Filtered value.
	 * @return void
	 */
	public function test_a_junk_filter_falls_back( $junk ): void {
		add_filter(
			'pph_request_types',
			static function () use ( $junk ) {
				return $junk;
			}
		);

		$types = Request::types();

		$this->assertNotEmpty( $types );

		foreach ( $types as $type ) {
			$this->assertIsString( $type );
			$this->assertLessThanOrEqual( 20, strlen( $type ) );
			$this->assertNotSame( '', $type );
		}
	}

	/**
	 * Values a filter might wrongly return.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function junk_provider(): array {
		return array(
			'null'          => array( null ),
			'string'        => array( 'cancellation' ),
			'empty array'   => array( array() ),
			'nested array'  => array( array( array( 'x' ) ) ),
			'empty strings' => array( array( '', '' ) ),
			'object'        => array( array( new \stdClass() ) ),
			'overlong'      => array( array( str_repeat( 'x', 40 ) ) ),
		);
	}

	/**
	 * Duplicate slugs from a filter collapse.
	 *
	 * @return void
	 */
	public function test_duplicates_collapse(): void {
		add_filter(
			'pph_request_statuses',
			static function ( array $statuses ): array {
				$statuses[] = Request::STATUS_PENDING;

				return $statuses;
			}
		);

		$this->assertSame( array_unique( Request::statuses() ), Request::statuses() );
	}
}
