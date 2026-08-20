<?php
/**
 * Column rules for the requests table.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

/**
 * What each column of `pph_requests` will accept.
 *
 * The repository owns statements; this owns the invariants those statements
 * depend on. Keeping them apart means the rules can be read — and tested —
 * without a database, and a value that would not fit the column is rejected
 * before it reaches one.
 *
 * This is not a substitute for sanitising input or escaping output. Notes are
 * length-capped here but not stripped of markup: callers pass user input through
 * Security\Sanitizer first, and every reader escapes at the point of output. It
 * is the last gate in front of the table, and it fails loudly rather than
 * truncating silently, except where the spec asks for a cap.
 *
 * @since 0.2.0
 */
final class RequestColumns {

	/**
	 * Writable columns and their $wpdb format specifiers.
	 *
	 * @var array<string, string>
	 */
	public const FORMATS = array(
		'order_id'            => '%d',
		'customer_id'         => '%d',
		'customer_email_hash' => '%s',
		'type'                => '%s',
		'status'              => '%s',
		'reason_code'         => '%s',
		'customer_note'       => '%s',
		'admin_note'          => '%s',
		'amount'              => '%s',
		'currency'            => '%s',
		'source'              => '%s',
		'created_at'          => '%s',
		'updated_at'          => '%s',
		'resolved_at'         => '%s',
		'resolved_by'         => '%d',
	);

	/**
	 * Columns an update may not change.
	 *
	 * A request belongs to the order it was raised against; moving it would hand
	 * one customer's request to another customer's order.
	 *
	 * @var string[]
	 */
	public const IMMUTABLE = array( 'id', 'order_id', 'created_at' );

	/**
	 * Validates and normalises a row for writing.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException On an unknown column or an unusable value.
	 */
	public static function normalise( array $data ): array {
		$row = array();

		foreach ( $data as $column => $value ) {
			if ( ! isset( self::FORMATS[ $column ] ) ) {
				throw new \InvalidArgumentException( esc_html( 'Unknown request column: ' . RequestQuery::describe( $column ) ) );
			}

			$row[ (string) $column ] = self::value( (string) $column, $value );
		}

		return $row;
	}

	/**
	 * Format specifiers matching a row's columns, in the same order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row Row to write.
	 * @return list<string>
	 */
	public static function formats( array $row ): array {
		$formats = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = self::FORMATS[ $column ];
		}

		return $formats;
	}

	/**
	 * Normalises one column value.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value.
	 * @return mixed
	 * @throws \InvalidArgumentException When the value cannot be stored.
	 */
	private static function value( string $column, $value ) {
		switch ( $column ) {
			case 'type':
				return self::in_vocabulary( $value, Request::types(), 'type' );
			case 'status':
				return self::in_vocabulary( $value, Request::statuses(), 'status' );
			case 'source':
				return self::in_vocabulary( $value, Request::sources(), 'source' );
			case 'customer_email_hash':
				return self::email_hash( $value );
			case 'reason_code':
				return self::bounded_string( $value, 50, 'reason_code' );
			case 'currency':
				return self::currency( $value );
			case 'customer_note':
			case 'admin_note':
				return self::note( $value );
			case 'amount':
				return self::amount( $value );
			case 'created_at':
			case 'updated_at':
				return self::datetime( $value, false );
			case 'resolved_at':
				return self::datetime( $value, true );
			default:
				return null === $value ? null : max( 0, (int) $value );
		}
	}

	/**
	 * Asserts a slug belongs to a vocabulary.
	 *
	 * @param mixed    $value      Candidate slug.
	 * @param string[] $vocabulary Accepted slugs.
	 * @param string   $column     Column name, for the message.
	 * @return string
	 * @throws \InvalidArgumentException When the slug is not accepted.
	 */
	private static function in_vocabulary( $value, array $vocabulary, string $column ): string {
		$slug = is_scalar( $value ) ? (string) $value : '';

		if ( ! in_array( $slug, $vocabulary, true ) ) {
			throw new \InvalidArgumentException( esc_html( 'Unsupported request ' . $column . ': ' . RequestQuery::describe( $slug ) ) );
		}

		return $slug;
	}

	/**
	 * Validates the email hash, which may be empty for merchant-raised requests.
	 *
	 * @param mixed $value Candidate digest.
	 * @return string
	 * @throws \InvalidArgumentException When the value is neither empty nor a SHA-256 digest.
	 */
	private static function email_hash( $value ): string {
		$hash = is_scalar( $value ) ? strtolower( (string) $value ) : '';

		if ( '' !== $hash && 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			throw new \InvalidArgumentException( 'customer_email_hash expects a SHA-256 hex digest.' );
		}

		return $hash;
	}

	/**
	 * Caps a note at the length the spec fixes for the column.
	 *
	 * Truncating rather than throwing is deliberate here: a customer typing past
	 * the limit is not an error worth failing their request over.
	 *
	 * @param mixed $value Candidate note.
	 * @return string|null
	 */
	private static function note( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$note = is_scalar( $value ) ? (string) $value : '';

		return mb_substr( $note, 0, Request::NOTE_MAX_LENGTH );
	}

	/**
	 * Normalises a monetary amount to a DECIMAL(19,4) string.
	 *
	 * @param mixed $value Candidate amount.
	 * @return string|null
	 * @throws \InvalidArgumentException When the value is not numeric.
	 */
	private static function amount( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( 'amount expects a numeric value.' );
		}

		return number_format( (float) $value, 4, '.', '' );
	}

	/**
	 * Normalises an ISO 4217 currency code.
	 *
	 * @param mixed $value Candidate code.
	 * @return string|null
	 * @throws \InvalidArgumentException When the value is not three letters.
	 */
	private static function currency( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$code = strtoupper( is_scalar( $value ) ? (string) $value : '' );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
			throw new \InvalidArgumentException( 'currency expects a three-letter code.' );
		}

		return $code;
	}

	/**
	 * Validates a UTC datetime.
	 *
	 * @param mixed $value    Candidate datetime.
	 * @param bool  $nullable Whether null is acceptable.
	 * @return string|null
	 * @throws \InvalidArgumentException When the value is not `Y-m-d H:i:s`.
	 */
	private static function datetime( $value, bool $nullable ): ?string {
		if ( $nullable && ( null === $value || '' === $value ) ) {
			return null;
		}

		$candidate = is_scalar( $value ) ? (string) $value : '';
		$parsed    = \DateTimeImmutable::createFromFormat( RequestQuery::DATE_FORMAT, $candidate, new \DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( RequestQuery::DATE_FORMAT ) !== $candidate ) {
			throw new \InvalidArgumentException( esc_html( 'Datetimes must be UTC ' . RequestQuery::DATE_FORMAT . '.' ) );
		}

		return $candidate;
	}

	/**
	 * Caps a short string column, rejecting non-scalars.
	 *
	 * @param mixed  $value  Candidate value.
	 * @param int    $length Maximum length.
	 * @param string $column Column name, for the message.
	 * @return string|null
	 * @throws \InvalidArgumentException When the value is longer than the column.
	 */
	private static function bounded_string( $value, int $length, string $column ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$text = is_scalar( $value ) ? (string) $value : '';

		if ( '' === $text || strlen( $text ) > $length ) {
			throw new \InvalidArgumentException( esc_html( $column . ' must be 1 to ' . $length . ' characters.' ) );
		}

		return $text;
	}
}
