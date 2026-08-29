<?php
/**
 * Column rule unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestColumns;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The last gate in front of the table. Anything that would not fit the column,
 * or does not belong to the accepted vocabulary, has to be refused here.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Requests\RequestColumns
 */
final class RequestColumnsTest extends TestCase {

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
	 * A complete, valid row passes through unchanged.
	 *
	 * @return void
	 */
	public function test_a_valid_row_normalises(): void {
		$row = RequestColumns::normalise(
			array(
				'order_id'    => 42,
				'type'        => Request::TYPE_CANCELLATION,
				'status'      => Request::STATUS_PENDING,
				'source'      => Request::SOURCE_ACCOUNT,
				'created_at'  => '2026-08-20 10:00:00',
				'updated_at'  => '2026-08-20 10:00:00',
				'resolved_at' => null,
			)
		);

		$this->assertSame( 42, $row['order_id'] );
		$this->assertSame( 'cancellation', $row['type'] );
		$this->assertNull( $row['resolved_at'] );
	}

	/**
	 * An unknown column is refused: a typo must not become a silent no-op.
	 *
	 * @return void
	 */
	public function test_an_unknown_column_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'refund_amount' => '10.00' ) );
	}

	/**
	 * A value outside the vocabulary is refused for each vocabulary column.
	 *
	 * @dataProvider vocabulary_provider
	 *
	 * @param string $column Column name.
	 * @return void
	 */
	public function test_a_value_outside_the_vocabulary_is_rejected( string $column ): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( $column => 'not-a-real-value' ) );
	}

	/**
	 * Columns backed by a vocabulary.
	 *
	 * @return array<string, array{string}>
	 */
	public static function vocabulary_provider(): array {
		return array(
			'type'   => array( 'type' ),
			'status' => array( 'status' ),
			'source' => array( 'source' ),
		);
	}

	/**
	 * A type added by a filter becomes writable, without a core change.
	 *
	 * @return void
	 */
	public function test_a_filtered_type_becomes_writable(): void {
		add_filter(
			'wpmphub_request_types',
			static function ( array $types ): array {
				$types[] = 'exchange';

				return $types;
			}
		);

		$this->assertSame( array( 'type' => 'exchange' ), RequestColumns::normalise( array( 'type' => 'exchange' ) ) );
	}

	/**
	 * The customer note is capped at the length the column allows.
	 *
	 * @return void
	 */
	public function test_a_long_note_is_capped_not_rejected(): void {
		$row = RequestColumns::normalise( array( 'customer_note' => str_repeat( 'x', 5000 ) ) );

		$this->assertSame( Request::NOTE_MAX_LENGTH, mb_strlen( (string) $row['customer_note'] ) );
	}

	/**
	 * Multibyte notes are cut by character, not by byte.
	 *
	 * @return void
	 */
	public function test_a_multibyte_note_is_cut_by_character(): void {
		$row = RequestColumns::normalise( array( 'customer_note' => str_repeat( 'こんにちは', 500 ) ) );

		$this->assertSame( Request::NOTE_MAX_LENGTH, mb_strlen( (string) $row['customer_note'] ) );
	}

	/**
	 * A null note stays null rather than becoming an empty string.
	 *
	 * @return void
	 */
	public function test_a_null_note_stays_null(): void {
		$this->assertNull( RequestColumns::normalise( array( 'admin_note' => null ) )['admin_note'] );
	}

	/**
	 * Amounts normalise to the column's four decimal places.
	 *
	 * @return void
	 */
	public function test_amounts_normalise_to_four_decimals(): void {
		$this->assertSame( '10.0000', RequestColumns::normalise( array( 'amount' => 10 ) )['amount'] );
		$this->assertSame( '10.5000', RequestColumns::normalise( array( 'amount' => '10.5' ) )['amount'] );
		$this->assertSame( '0.3333', RequestColumns::normalise( array( 'amount' => 1 / 3 ) )['amount'] );
		$this->assertNull( RequestColumns::normalise( array( 'amount' => null ) )['amount'] );
	}

	/**
	 * A non-numeric amount is refused.
	 *
	 * @return void
	 */
	public function test_a_non_numeric_amount_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'amount' => '10.00 GBP' ) );
	}

	/**
	 * Currency codes are upper-cased and must be three letters.
	 *
	 * @return void
	 */
	public function test_currency_is_three_letters(): void {
		$this->assertSame( 'GBP', RequestColumns::normalise( array( 'currency' => 'gbp' ) )['currency'] );

		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'currency' => 'GBPX' ) );
	}

	/**
	 * The email hash is either empty or a SHA-256 digest.
	 *
	 * @return void
	 */
	public function test_the_email_hash_is_a_digest_or_empty(): void {
		$hash = str_repeat( 'f0', 32 );

		$this->assertSame( '', RequestColumns::normalise( array( 'customer_email_hash' => '' ) )['customer_email_hash'] );
		$this->assertSame( $hash, RequestColumns::normalise( array( 'customer_email_hash' => strtoupper( $hash ) ) )['customer_email_hash'] );

		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'customer_email_hash' => 'someone@example.com' ) );
	}

	/**
	 * A plaintext email must never be storable in the hash column.
	 *
	 * @return void
	 */
	public function test_a_plaintext_email_cannot_be_stored(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'customer_email_hash' => 'buyer@example.com' ) );
	}

	/**
	 * Datetimes must be the stored UTC format; created_at may not be null.
	 *
	 * @return void
	 */
	public function test_datetimes_must_be_utc_format(): void {
		$this->assertSame(
			'2026-08-20 10:00:00',
			RequestColumns::normalise( array( 'created_at' => '2026-08-20 10:00:00' ) )['created_at']
		);

		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'created_at' => null ) );
	}

	/**
	 * The resolved_at column is the one nullable datetime.
	 *
	 * @return void
	 */
	public function test_resolved_at_may_be_null(): void {
		$this->assertNull( RequestColumns::normalise( array( 'resolved_at' => null ) )['resolved_at'] );
		$this->assertNull( RequestColumns::normalise( array( 'resolved_at' => '' ) )['resolved_at'] );
	}

	/**
	 * A reason code longer than the column is refused.
	 *
	 * @return void
	 */
	public function test_an_overlong_reason_code_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		RequestColumns::normalise( array( 'reason_code' => str_repeat( 'r', 51 ) ) );
	}

	/**
	 * Format specifiers come back in the row's own column order.
	 *
	 * @return void
	 */
	public function test_formats_follow_the_row_order(): void {
		$row = array(
			'type'     => Request::TYPE_HELP,
			'order_id' => 5,
			'amount'   => '1.0000',
		);

		$this->assertSame( array( '%s', '%d', '%s' ), RequestColumns::formats( $row ) );
	}

	/**
	 * Ids are integers and never negative.
	 *
	 * @return void
	 */
	public function test_ids_are_non_negative_integers(): void {
		$row = RequestColumns::normalise(
			array(
				'order_id'    => '42abc',
				'customer_id' => -9,
				'resolved_by' => null,
			)
		);

		$this->assertSame( 42, $row['order_id'] );
		$this->assertSame( 0, $row['customer_id'] );
		$this->assertNull( $row['resolved_by'] );
	}
}
