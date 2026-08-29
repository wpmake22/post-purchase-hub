<?php
/**
 * Schema integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Schema;

/**
 * Asserts the tables exist with exactly the indexes docs/SPEC.md Phase 7
 * specifies. Indexes are the whole reason these are custom tables rather than
 * order meta, so an index quietly missing is the bug worth catching.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Install\Schema
 */
final class SchemaTest extends \WP_UnitTestCase {

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
	 * Both tables are created, including the one nothing writes to yet.
	 *
	 * @return void
	 */
	public function test_both_tables_exist(): void {
		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
		$this->assertTrue( Schema::table_exists( Schema::request_items_table() ) );
	}

	/**
	 * The requests table carries exactly the specified indexes.
	 *
	 * @return void
	 */
	public function test_the_requests_table_indexes(): void {
		$this->assertSame(
			array(
				'PRIMARY'             => array( 'id' ),
				'created_at'          => array( 'created_at' ),
				'customer_id'         => array( 'customer_id' ),
				'email_hash_created'  => array( 'customer_email_hash', 'created_at' ),
				'order_id'            => array( 'order_id' ),
				'status_type_created' => array( 'status', 'type', 'created_at' ),
			),
			$this->indexes( Schema::requests_table() )
		);
	}

	/**
	 * The items table carries exactly the specified indexes.
	 *
	 * @return void
	 */
	public function test_the_request_items_table_indexes(): void {
		$this->assertSame(
			array(
				'PRIMARY'    => array( 'id' ),
				'product_id' => array( 'product_id' ),
				'request_id' => array( 'request_id' ),
			),
			$this->indexes( Schema::request_items_table() )
		);
	}

	/**
	 * Every column in the spec exists, with the specified nullability.
	 *
	 * @return void
	 */
	public function test_the_requests_table_columns(): void {
		$columns = $this->columns( Schema::requests_table() );

		$this->assertSame(
			array(
				'id',
				'order_id',
				'customer_id',
				'customer_email_hash',
				'type',
				'status',
				'reason_code',
				'customer_note',
				'admin_note',
				'amount',
				'currency',
				'source',
				'created_at',
				'updated_at',
				'resolved_at',
				'resolved_by',
			),
			array_keys( $columns )
		);

		$this->assertStringContainsString( 'bigint', $columns['order_id']['Type'] );
		$this->assertStringContainsString( 'char(64)', $columns['customer_email_hash']['Type'] );
		$this->assertStringContainsString( 'decimal(19,4)', $columns['amount']['Type'] );
		$this->assertSame( 'NO', $columns['created_at']['Null'] );
		$this->assertSame( 'YES', $columns['resolved_at']['Null'] );
	}

	/**
	 * Money is a decimal, not a float: a float cannot be reconciled.
	 *
	 * @return void
	 */
	public function test_money_columns_are_decimal(): void {
		$requests = $this->columns( Schema::requests_table() );
		$items    = $this->columns( Schema::request_items_table() );

		$this->assertStringStartsWith( 'decimal', $requests['amount']['Type'] );
		$this->assertStringStartsWith( 'decimal', $items['line_total']['Type'] );
	}

	/**
	 * Running the install again changes nothing and raises nothing.
	 *
	 * @return void
	 */
	public function test_install_is_idempotent(): void {
		$before = $this->indexes( Schema::requests_table() );

		Schema::install();
		Schema::install();

		$this->assertSame( $before, $this->indexes( Schema::requests_table() ) );
		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
	}

	/**
	 * Table names are prefixed for the site, so multisite cannot collide.
	 *
	 * @return void
	 */
	public function test_table_names_are_prefixed(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'wpmphub_requests', Schema::requests_table() );
		$this->assertSame( $wpdb->prefix . 'wpmphub_request_items', Schema::request_items_table() );
		$this->assertContains( Schema::requests_table(), Schema::tables() );
	}

	/**
	 * A table that does not exist is reported as absent rather than assumed.
	 *
	 * @return void
	 */
	public function test_a_missing_table_is_reported(): void {
		$this->assertFalse( Schema::table_exists( Schema::requests_table() . '_nope' ) );
	}

	/**
	 * Index name => ordered column list.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<string, list<string>>
	 */
	private function indexes( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test; the name comes from Schema.
		$rows = (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$indexes = array();

		foreach ( $rows as $row ) {
			$indexes[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}

		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );
			$indexes[ $name ] = array_values( $columns );
		}

		ksort( $indexes );

		return $indexes;
	}

	/**
	 * Column name => description row.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<string, array<string, string|null>>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test; the name comes from Schema.
		$rows = (array) $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );

		$columns = array();

		foreach ( $rows as $row ) {
			$columns[ (string) $row['Field'] ] = $row;
		}

		return $columns;
	}
}
