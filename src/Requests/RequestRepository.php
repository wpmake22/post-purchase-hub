<?php
/**
 * Persistence for customer requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Install\Schema;

/**
 * The only class that reads or writes `pph_requests`.
 *
 * Every statement is prepared. Identifiers — the select list, the sort column,
 * the direction — come from whitelists in this file and in RequestQuery, never
 * from a caller's string. Lists select an explicit column set rather than `*`,
 * so adding a column later cannot quietly widen what the admin queue pulls into
 * memory.
 *
 * Column invariants live in RequestColumns; escaping for output stays with
 * whoever prints the value.
 *
 * This class performs no authorisation. find() will return any row it is given
 * the id of, because a repository that guessed at permissions would be a second
 * place where ownership is decided. Callers look a request up by id and then
 * re-verify it against its order's owner through Security\OwnershipResolver —
 * per docs/SPEC.md Phase 8, a request_id from a request body is never trusted on
 * its own.
 *
 * @since 0.2.0
 */
final class RequestRepository {

	/**
	 * Columns read back for a request, in table order.
	 *
	 * @var string[]
	 */
	private const COLUMNS = array(
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
	);

	/**
	 * Inserts a request and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int
	 * @throws \InvalidArgumentException On an unknown column or an unusable value.
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = gmdate( RequestQuery::DATE_FORMAT );

		$row = RequestColumns::normalise(
			array_merge(
				array(
					'status'     => Request::STATUS_PENDING,
					'created_at' => $now,
					'updated_at' => $now,
				),
				$data
			)
		);

		foreach ( array( 'order_id', 'type', 'source' ) as $required ) {
			if ( empty( $row[ $required ] ) ) {
				throw new \InvalidArgumentException( esc_html( 'A request requires a non-empty ' . $required . '.' ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; $wpdb->insert prepares its own values.
		$inserted = $wpdb->insert( Schema::requests_table(), $row, RequestColumns::formats( $row ) );

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Could not store the request.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Finds one request by id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id Request id.
	 * @return Request|null
	 */
	public function find( int $id ): ?Request {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}

		$sql = sprintf( 'SELECT %s FROM %s WHERE id = %%d', $this->select_list(), Schema::requests_table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; identifiers are class constants, the value is prepared here.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );

		return is_array( $row ) ? Request::from_row( $row ) : null;
	}

	/**
	 * Finds the requests raised against one order, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $filters  Additional filters.
	 * @return list<Request>
	 * @throws \InvalidArgumentException On an unknown filter.
	 */
	public function find_by_order( int $order_id, array $filters = array() ): array {
		if ( $order_id < 1 ) {
			return array();
		}

		return $this->query(
			array_merge( $filters, array( 'order_id' => $order_id ) ),
			'created_at',
			'DESC',
			1,
			RequestQuery::MAX_PER_PAGE
		);
	}

	/**
	 * Updates a request.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id      Request id.
	 * @param array<string, mixed> $changes Column values to change.
	 * @return bool True when a row changed.
	 * @throws \InvalidArgumentException On an unknown, immutable or unusable column.
	 */
	public function update( int $id, array $changes ): bool {
		global $wpdb;

		if ( $id < 1 || ! $changes ) {
			return false;
		}

		foreach ( array_keys( $changes ) as $column ) {
			if ( in_array( $column, RequestColumns::IMMUTABLE, true ) ) {
				throw new \InvalidArgumentException( esc_html( 'A request\'s ' . RequestQuery::describe( $column ) . ' cannot be changed.' ) );
			}
		}

		$row = RequestColumns::normalise(
			array_merge( $changes, array( 'updated_at' => gmdate( RequestQuery::DATE_FORMAT ) ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; $wpdb->update prepares its own values.
		$updated = $wpdb->update( Schema::requests_table(), $row, array( 'id' => $id ), RequestColumns::formats( $row ), array( '%d' ) );

		return false !== $updated && $updated > 0;
	}

	/**
	 * Queries requests.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $filters  Filters, per RequestQuery::where().
	 * @param string               $orderby  Whitelisted sort column.
	 * @param string               $order    ASC or DESC.
	 * @param int                  $page     One-based page.
	 * @param int                  $per_page Rows per page.
	 * @return list<Request>
	 * @throws \InvalidArgumentException On an unknown filter, column or direction.
	 */
	public function query( array $filters = array(), string $orderby = 'created_at', string $order = 'DESC', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$where = RequestQuery::where( $filters );
		$limit = RequestQuery::limit( $page, $per_page );

		$sql = self::squash(
			sprintf(
				'SELECT %s FROM %s %s %s %s',
				$this->select_list(),
				Schema::requests_table(),
				$where['sql'],
				RequestQuery::order_by( $orderby, $order ),
				$limit['sql']
			)
		);

		$args = array_merge( $where['args'], $limit['args'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; identifiers are whitelisted and every value is a placeholder argument.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return array_map(
			static function ( array $row ): Request {
				return Request::from_row( $row );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Counts requests matching the filters.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $filters Filters, per RequestQuery::where().
	 * @return int
	 * @throws \InvalidArgumentException On an unknown filter.
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;

		$where = RequestQuery::where( $filters );
		$sql   = self::squash( sprintf( 'SELECT COUNT(*) FROM %s %s', Schema::requests_table(), $where['sql'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; identifiers are class constants and every value is a placeholder argument.
		return (int) $wpdb->get_var( $where['args'] ? $wpdb->prepare( $sql, ...$where['args'] ) : $sql );
	}

	/**
	 * The explicit select list.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private function select_list(): string {
		return implode( ', ', self::COLUMNS );
	}

	/**
	 * Collapses the whitespace left by an empty clause.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	private static function squash( string $sql ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	}
}
