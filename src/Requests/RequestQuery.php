<?php
/**
 * Query fragment builder for the requests table.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

/**
 * Turns a filter array into prepared SQL fragments and their arguments.
 *
 * Split out of the repository for one reason: this is where request input meets
 * SQL, and it is testable without a database. Column names, sort directions and
 * pagination bounds come from the whitelists here; every value leaves as a
 * placeholder argument for $wpdb->prepare(). Nothing in this class calls
 * WordPress, so there is no filter that can widen what it accepts.
 *
 * Membership checks against the filterable type, status and source vocabularies
 * belong to writes, not reads: filtering a list by an unrecognised status is
 * harmless and returns nothing, whereas storing one corrupts the table.
 *
 * @since 0.2.0
 */
final class RequestQuery {

	/**
	 * Columns a caller may sort by.
	 *
	 * @var string[]
	 */
	public const ORDERBY = array(
		'id',
		'order_id',
		'customer_id',
		'type',
		'status',
		'created_at',
		'updated_at',
		'resolved_at',
	);

	/**
	 * Accepted sort directions.
	 *
	 * @var string[]
	 */
	public const ORDER = array( 'ASC', 'DESC' );

	/**
	 * Hard ceiling on rows per page, whatever the caller asks for.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Stored datetime format, UTC.
	 *
	 * @var string
	 */
	public const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Filters that accept one value or a list of values.
	 *
	 * @var array<string, string>
	 */
	private const LIST_FILTERS = array(
		'order_id'    => 'int',
		'customer_id' => 'int',
		'type'        => 'slug',
		'status'      => 'slug',
		'source'      => 'slug',
	);

	/**
	 * Filters that accept a UTC datetime, mapped to their SQL comparison.
	 *
	 * `created_after` and `created_since` differ only in their boundary, and
	 * both exist because their callers mean different things by "after". A
	 * date-range filter on the admin list is a day boundary and must include
	 * the moment itself, or an order placed at exactly midnight vanishes from
	 * its own day. A watermark — "what has happened since I last looked" — must
	 * exclude it, or the record sitting exactly on the mark is reported as new
	 * every time and a digest that should fall silent never does.
	 *
	 * @var array<string, string>
	 */
	private const DATE_FILTERS = array(
		'created_after'   => 'created_at >= %s',
		'created_before'  => 'created_at <= %s',
		'created_since'   => 'created_at > %s',
		'resolved_before' => 'resolved_at <= %s',
	);

	/**
	 * Builds a WHERE clause and its arguments.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $filters Filters to apply.
	 * @return array{sql: string, args: list<mixed>}
	 * @throws \InvalidArgumentException On an unknown filter or an unusable value.
	 */
	public static function where( array $filters ): array {
		$clauses = array();
		$args    = array();

		foreach ( $filters as $key => $value ) {
			if ( isset( self::LIST_FILTERS[ $key ] ) ) {
				$values = self::value_list( $key, $value, self::LIST_FILTERS[ $key ] );

				$clauses[] = sprintf(
					'%s IN (%s)',
					$key,
					implode( ', ', array_fill( 0, count( $values ), 'int' === self::LIST_FILTERS[ $key ] ? '%d' : '%s' ) )
				);

				$args = array_merge( $args, $values );
				continue;
			}

			if ( isset( self::DATE_FILTERS[ $key ] ) ) {
				$clauses[] = self::DATE_FILTERS[ $key ];
				$args[]    = self::datetime( $key, $value );
				continue;
			}

			if ( 'customer_email_hash' === $key ) {
				$clauses[] = 'customer_email_hash = %s';
				$args[]    = self::email_hash( $value );
				continue;
			}

			self::fail( 'Unknown request filter: ' . self::describe( $key ) );
		}

		return array(
			'sql'  => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'args' => $args,
		);
	}

	/**
	 * Builds an ORDER BY clause from whitelisted identifiers.
	 *
	 * Both parts are identifiers, so neither can be a placeholder. They are
	 * therefore compared against a whitelist and rejected outright — never
	 * escaped and passed through, and never silently swapped for a default,
	 * because a caller that sends an unexpected column has a bug worth seeing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderby Column to sort by.
	 * @param string $order   ASC or DESC.
	 * @return string
	 * @throws \InvalidArgumentException When either value is not whitelisted.
	 */
	public static function order_by( string $orderby, string $order ): string {
		if ( ! in_array( $orderby, self::ORDERBY, true ) ) {
			self::fail( 'Unsupported orderby column: ' . self::describe( $orderby ) );
		}

		$direction = strtoupper( $order );

		if ( ! in_array( $direction, self::ORDER, true ) ) {
			self::fail( 'Unsupported order direction: ' . self::describe( $order ) );
		}

		// A stable tiebreaker: paging by a non-unique column otherwise repeats or skips rows.
		return 'id' === $orderby
			? sprintf( 'ORDER BY id %s', $direction )
			: sprintf( 'ORDER BY %s %s, id %s', $orderby, $direction, $direction );
	}

	/**
	 * Builds a LIMIT clause and its arguments.
	 *
	 * @since 0.2.0
	 *
	 * @param int $page     One-based page number.
	 * @param int $per_page Rows per page, clamped to MAX_PER_PAGE.
	 * @return array{sql: string, args: list<int>}
	 */
	public static function limit( int $page, int $per_page ): array {
		$per_page = min( max( 1, $per_page ), self::MAX_PER_PAGE );
		$page     = max( 1, $page );

		return array(
			'sql'  => 'LIMIT %d OFFSET %d',
			'args' => array( $per_page, ( $page - 1 ) * $per_page ),
		);
	}

	/**
	 * Whether a column may be sorted on.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderby Column name.
	 * @return bool
	 */
	public static function is_sortable( string $orderby ): bool {
		return in_array( $orderby, self::ORDERBY, true );
	}

	/**
	 * Normalises a scalar or list filter value.
	 *
	 * @since 0.2.0
	 * @param string $key   Filter name.
	 * @param mixed  $value Filter value.
	 * @param string $type  `int` or `slug`.
	 * @return list<int|string>
	 * @throws \InvalidArgumentException When the value is empty or unusable.
	 */
	private static function value_list( string $key, $value, string $type ): array {
		$values = is_array( $value ) ? array_values( $value ) : array( $value );
		$clean  = array();

		foreach ( $values as $item ) {
			if ( ! is_scalar( $item ) ) {
				self::fail( 'Filter ' . self::describe( $key ) . ' accepts scalars only.' );
			}

			if ( 'int' === $type ) {
				$clean[] = max( 0, (int) $item );
				continue;
			}

			$slug = trim( (string) $item );

			if ( '' === $slug || strlen( $slug ) > 20 ) {
				self::fail( 'Filter ' . self::describe( $key ) . ' received an unusable value.' );
			}

			$clean[] = $slug;
		}

		if ( ! $clean ) {
			self::fail( 'Filter ' . self::describe( $key ) . ' received no values.' );
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validates a UTC datetime string.
	 *
	 * @since 0.2.0
	 * @param string $key   Filter name.
	 * @param mixed  $value Filter value.
	 * @return string
	 * @throws \InvalidArgumentException When the value is not `Y-m-d H:i:s`.
	 */
	private static function datetime( string $key, $value ): string {
		$candidate = is_scalar( $value ) ? (string) $value : '';
		$parsed    = \DateTimeImmutable::createFromFormat( self::DATE_FORMAT, $candidate, new \DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( self::DATE_FORMAT ) !== $candidate ) {
			self::fail( 'Filter ' . self::describe( $key ) . ' expects a UTC Y-m-d H:i:s datetime.' );
		}

		return $candidate;
	}

	/**
	 * Validates a SHA-256 hex digest.
	 *
	 * @since 0.2.0
	 * @param mixed $value Filter value.
	 * @return string
	 * @throws \InvalidArgumentException When the value is not a 64-character hex string.
	 */
	private static function email_hash( $value ): string {
		$candidate = is_scalar( $value ) ? strtolower( (string) $value ) : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $candidate ) ) {
			self::fail( 'Filter customer_email_hash expects a SHA-256 hex digest.' );
		}

		return $candidate;
	}

	/**
	 * Rejects a filter value.
	 *
	 * Routed through one place so the class needs no WordPress escaping helper:
	 * describe() has already reduced any untrusted fragment to inert characters.
	 *
	 * @since 0.2.0
	 * @param string $message Message, already inert.
	 * @return never
	 * @throws \InvalidArgumentException Always.
	 */
	private static function fail( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- describe() has stripped everything but [A-Za-z0-9_.- ] from any untrusted fragment.
		throw new \InvalidArgumentException( $message );
	}

	/**
	 * Renders an untrusted value safely for an exception message.
	 *
	 * Exception text can surface in a fatal-error screen or a log, so a rejected
	 * value is reduced to something inert first. Shared with RequestColumns.
	 *
	 * @since 0.2.0
	 * @param mixed $value Value to describe.
	 * @return string
	 */
	public static function describe( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : gettype( $value );

		return (string) preg_replace( '/[^A-Za-z0-9_.\- ]/', '', substr( $text, 0, 40 ) );
	}
}
