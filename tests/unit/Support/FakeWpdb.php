<?php
/**
 * Minimal `$wpdb` stand-in for the unit suite.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

/**
 * Enough of `wpdb` for schema introspection, and nothing more.
 *
 * Most of this plugin's storage is behind `Requests\RequestRepository`, which
 * unit tests replace with a fake rather than faking the database (see
 * `Actions\FakeRequestHistory`). `Install\Schema::table_exists()` is the
 * exception: it is a static that asks the database directly, and
 * `Admin\HealthPanel` reports its answer. This double lets a test say "the
 * table is there" or "it is not" without a database.
 *
 * @since 0.14.0
 */
final class FakeWpdb {

	/**
	 * Table prefix, as wpdb exposes it.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Tables this fake database contains, fully prefixed.
	 *
	 * @var array<int, string>
	 */
	public array $tables = array();

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param array<int, string> $unprefixed Unprefixed table names that exist.
	 */
	public function __construct( array $unprefixed = array() ) {
		foreach ( $unprefixed as $table ) {
			$this->tables[] = $this->prefix . $table;
		}
	}

	/**
	 * Interpolates a query, close enough for `SHOW TABLES LIKE %s`.
	 *
	 * @since 0.14.0
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', (string) $arg, $query, 1 ) ?? $query;
		}

		return $query;
	}

	/**
	 * Answers `SHOW TABLES LIKE` from the declared table list.
	 *
	 * @since 0.14.0
	 *
	 * @param string $query Prepared query.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		foreach ( $this->tables as $table ) {
			if ( str_contains( $query, $table ) ) {
				return $table;
			}
		}

		return null;
	}
}
