<?php
/**
 * Query builder unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Requests\RequestQuery;

/**
 * This is where request input meets SQL, so these tests are the ones that
 * matter: every value must leave as a placeholder argument, and every
 * identifier must come from a whitelist or be rejected outright.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Requests\RequestQuery
 */
final class RequestQueryTest extends TestCase {

	/**
	 * No filters means no WHERE clause and no arguments.
	 *
	 * @return void
	 */
	public function test_no_filters_produces_no_clause(): void {
		$this->assertSame(
			array(
				'sql'  => '',
				'args' => array(),
			),
			RequestQuery::where( array() )
		);
	}

	/**
	 * Scalar filters become single-placeholder IN clauses.
	 *
	 * @return void
	 */
	public function test_a_scalar_filter_becomes_a_placeholder(): void {
		$where = RequestQuery::where( array( 'status' => 'pending' ) );

		$this->assertSame( 'WHERE status IN (%s)', $where['sql'] );
		$this->assertSame( array( 'pending' ), $where['args'] );
	}

	/**
	 * Lists produce one placeholder per value, de-duplicated.
	 *
	 * @return void
	 */
	public function test_a_list_filter_produces_one_placeholder_per_value(): void {
		$where = RequestQuery::where( array( 'status' => array( 'pending', 'approved', 'pending' ) ) );

		$this->assertSame( 'WHERE status IN (%s, %s)', $where['sql'] );
		$this->assertSame( array( 'pending', 'approved' ), $where['args'] );
	}

	/**
	 * Integer filters use %d and never carry a sign.
	 *
	 * @return void
	 */
	public function test_integer_filters_use_integer_placeholders(): void {
		$where = RequestQuery::where( array( 'order_id' => array( 42, '17', -5 ) ) );

		$this->assertSame( 'WHERE order_id IN (%d, %d, %d)', $where['sql'] );
		$this->assertSame( array( 42, 17, 0 ), $where['args'] );
	}

	/**
	 * Several filters are joined with AND, in the caller's order.
	 *
	 * @return void
	 */
	public function test_filters_are_combined_with_and(): void {
		$where = RequestQuery::where(
			array(
				'status'        => 'pending',
				'type'          => 'cancellation',
				'created_after' => '2026-01-01 00:00:00',
			)
		);

		$this->assertSame( 'WHERE status IN (%s) AND type IN (%s) AND created_at >= %s', $where['sql'] );
		$this->assertSame( array( 'pending', 'cancellation', '2026-01-01 00:00:00' ), $where['args'] );
	}

	/**
	 * An unknown filter is refused rather than ignored: a dropped filter is how
	 * a query silently returns rows it should not.
	 *
	 * @return void
	 */
	public function test_an_unknown_filter_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'customer_email' => 'someone@example.com' ) );
	}

	/**
	 * Injection attempts through a value stay values.
	 *
	 * @dataProvider injection_provider
	 *
	 * @param string $payload Attempted injection.
	 * @return void
	 */
	public function test_injection_through_a_value_stays_an_argument( string $payload ): void {
		$where = RequestQuery::where( array( 'customer_id' => $payload ) );

		// The clause is fixed text; whatever the payload was, it is now one integer.
		$this->assertSame( 'WHERE customer_id IN (%d)', $where['sql'] );
		$this->assertCount( 1, $where['args'] );
		$this->assertIsInt( $where['args'][0] );
	}

	/**
	 * Injection attempts through a slug value stay values.
	 *
	 * @dataProvider injection_provider
	 *
	 * @param string $payload Attempted injection.
	 * @return void
	 */
	public function test_injection_through_a_slug_stays_an_argument( string $payload ): void {
		try {
			$where = RequestQuery::where( array( 'status' => $payload ) );
		} catch ( \InvalidArgumentException $e ) {
			// Rejected outright is also an acceptable outcome.
			$this->assertStringNotContainsString( 'DROP TABLE', $e->getMessage() );
			return;
		}

		$this->assertSame( 'WHERE status IN (%s)', $where['sql'] );
		$this->assertSame( array( $payload ), $where['args'] );
	}

	/**
	 * Injection payloads.
	 *
	 * @return array<string, array{string}>
	 */
	public static function injection_provider(): array {
		return array(
			'union'        => array( '1 UNION SELECT user_pass FROM wp_users' ),
			'drop'         => array( '1; DROP TABLE wp_posts; --' ),
			'comment'      => array( "1'/*" ),
			'quote'        => array( "' OR '1'='1" ),
			'backslash'    => array( '1\\' ),
			'placeholder'  => array( '%s' ),
			'sprintf_bomb' => array( '%1$s%2$s' ),
		);
	}

	/**
	 * Only whitelisted columns can be sorted on.
	 *
	 * @return void
	 */
	public function test_a_whitelisted_column_sorts(): void {
		$this->assertSame( 'ORDER BY created_at DESC, id DESC', RequestQuery::order_by( 'created_at', 'DESC' ) );
		$this->assertSame( 'ORDER BY id ASC', RequestQuery::order_by( 'id', 'asc' ) );
	}

	/**
	 * Sorting by a non-unique column adds a stable tiebreaker, or paging repeats
	 * and skips rows.
	 *
	 * @return void
	 */
	public function test_sorting_is_stable(): void {
		$this->assertStringEndsWith( ', id ASC', RequestQuery::order_by( 'status', 'ASC' ) );
	}

	/**
	 * An unexpected orderby value never reaches SQL.
	 *
	 * @dataProvider orderby_provider
	 *
	 * @param string $orderby Attempted sort column.
	 * @return void
	 */
	public function test_an_unexpected_orderby_is_rejected( string $orderby ): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::order_by( $orderby, 'ASC' );
	}

	/**
	 * Sort columns that must be refused.
	 *
	 * @return array<string, array{string}>
	 */
	public static function orderby_provider(): array {
		return array(
			'unknown column' => array( 'customer_note' ),
			'injection'      => array( 'id; DROP TABLE wp_posts' ),
			'subquery'       => array( '(SELECT 1)' ),
			'expression'     => array( 'created_at, (SELECT user_pass FROM wp_users LIMIT 1)' ),
			'empty'          => array( '' ),
			'wildcard'       => array( '*' ),
			'sleep'          => array( 'SLEEP(5)' ),
			'wrong case'     => array( 'CREATED_AT' ),
		);
	}

	/**
	 * An unexpected sort direction never reaches SQL.
	 *
	 * @return void
	 */
	public function test_an_unexpected_direction_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::order_by( 'created_at', 'DESC; DROP TABLE wp_posts' );
	}

	/**
	 * Pagination is clamped and the offset follows from the page.
	 *
	 * @return void
	 */
	public function test_pagination_is_clamped(): void {
		$this->assertSame( array( 20, 0 ), RequestQuery::limit( 1, 20 )['args'] );
		$this->assertSame( array( 20, 40 ), RequestQuery::limit( 3, 20 )['args'] );
		$this->assertSame( array( RequestQuery::MAX_PER_PAGE, 0 ), RequestQuery::limit( 1, 5000 )['args'] );
		$this->assertSame( array( 1, 0 ), RequestQuery::limit( 0, 0 )['args'] );
		$this->assertSame( array( 1, 0 ), RequestQuery::limit( -3, -3 )['args'] );
	}

	/**
	 * The limit clause is placeholders only.
	 *
	 * @return void
	 */
	public function test_the_limit_clause_carries_no_values(): void {
		$this->assertSame( 'LIMIT %d OFFSET %d', RequestQuery::limit( 2, 10 )['sql'] );
	}

	/**
	 * The two "after" filters differ by their boundary, on purpose.
	 *
	 * `created_after` is a day boundary for the admin list and includes the
	 * moment itself; `created_since` is a watermark and excludes it. Getting
	 * these the same way round is what kept the admin digest sending forever
	 * after a request created in the same second as its last-sent marker.
	 *
	 * @return void
	 */
	public function test_created_after_is_inclusive_and_created_since_is_not(): void {
		$moment = '2026-08-22 07:19:33';

		$inclusive = RequestQuery::where( array( 'created_after' => $moment ) );
		$exclusive = RequestQuery::where( array( 'created_since' => $moment ) );

		$this->assertSame( 'WHERE created_at >= %s', $inclusive['sql'] );
		$this->assertSame( 'WHERE created_at > %s', $exclusive['sql'] );
		$this->assertSame( array( $moment ), $exclusive['args'] );
	}

	/**
	 * Datetime filters must be exactly the stored UTC format.
	 *
	 * @dataProvider bad_datetime_provider
	 *
	 * @param string $value Attempted datetime.
	 * @return void
	 */
	public function test_a_malformed_datetime_is_rejected( string $value ): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'created_before' => $value ) );
	}

	/**
	 * Datetimes that must be refused.
	 *
	 * @return array<string, array{string}>
	 */
	public static function bad_datetime_provider(): array {
		return array(
			'date only'    => array( '2026-08-20' ),
			'impossible'   => array( '2026-13-45 99:99:99' ),
			'iso'          => array( '2026-08-20T10:00:00Z' ),
			'injection'    => array( "2026-08-20 10:00:00' OR 1=1 --" ),
			'empty'        => array( '' ),
			'sql function' => array( 'NOW()' ),
			'partial time' => array( '2026-08-20 10:00' ),
		);
	}

	/**
	 * The email hash filter takes a SHA-256 digest and nothing else.
	 *
	 * @return void
	 */
	public function test_the_email_hash_filter_requires_a_digest(): void {
		$hash  = str_repeat( 'a1', 32 );
		$where = RequestQuery::where( array( 'customer_email_hash' => strtoupper( $hash ) ) );

		$this->assertSame( 'WHERE customer_email_hash = %s', $where['sql'] );
		$this->assertSame( array( $hash ), $where['args'] );

		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'customer_email_hash' => 'someone@example.com' ) );
	}

	/**
	 * A non-scalar filter value is refused before it can be cast.
	 *
	 * @return void
	 */
	public function test_a_non_scalar_value_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'status' => array( array( 'pending' ) ) ) );
	}

	/**
	 * An empty list is refused: `IN ()` is not valid SQL.
	 *
	 * @return void
	 */
	public function test_an_empty_list_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'status' => array() ) );
	}

	/**
	 * Overlong slugs cannot reach a VARCHAR(20) column.
	 *
	 * @return void
	 */
	public function test_an_overlong_slug_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestQuery::where( array( 'type' => str_repeat( 'a', 21 ) ) );
	}
}
