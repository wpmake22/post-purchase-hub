<?php
/**
 * Table definitions.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

/**
 * Owns the two custom tables: their names, their DDL and their removal.
 *
 * Both tables are created at install even though nothing writes to
 * `wpmphub_request_items` in 1.0. Per docs/SPEC.md Phase 7 that is deliberate:
 * item-level returns and the "top returned products" report both need
 * item-granular rows, and retrofitting normalisation onto shipped JSON is a
 * migration with a data-quality tail. An empty table costs nothing.
 *
 * @since 0.2.0
 */
final class Schema {

	/**
	 * Unprefixed name of the requests table.
	 *
	 * @var string
	 */
	public const REQUESTS = 'wpmphub_requests';

	/**
	 * Unprefixed name of the request items table.
	 *
	 * @var string
	 */
	public const REQUEST_ITEMS = 'wpmphub_request_items';

	/**
	 * Fully prefixed requests table name.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function requests_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::REQUESTS;
	}

	/**
	 * Fully prefixed request items table name.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function request_items_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::REQUEST_ITEMS;
	}

	/**
	 * Every table this plugin owns.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( self::requests_table(), self::request_items_table() );
	}

	/**
	 * Creates or updates both tables. Safe to run repeatedly.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::ddl() );
	}

	/**
	 * Drops both tables. Only ever called from uninstall.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL cannot be prepared; the name is built from $wpdb->prefix and a class constant.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @since 0.2.0
	 *
	 * @param string $table Fully prefixed table name.
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection has no cacheable counterpart.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * The DDL both tables are created from.
	 *
	 * Formatting matters here: dbDelta() parses this text, so every column sits
	 * on its own line and PRIMARY KEY carries two spaces. Column types follow
	 * WooCommerce's own schema in WC_Install::get_schema() so the two plugins
	 * behave the same way under dbDelta's comparison pass.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private static function ddl(): string {
		global $wpdb;

		$collate  = $wpdb->get_charset_collate();
		$requests = self::requests_table();
		$items    = self::request_items_table();

		return "CREATE TABLE {$requests} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
	customer_email_hash char(64) NOT NULL DEFAULT '',
	type varchar(20) NOT NULL,
	status varchar(20) NOT NULL,
	reason_code varchar(50) DEFAULT NULL,
	customer_note text NULL,
	admin_note text NULL,
	amount decimal(19,4) DEFAULT NULL,
	currency char(3) DEFAULT NULL,
	source varchar(20) NOT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	resolved_at datetime NULL DEFAULT NULL,
	resolved_by bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY order_id (order_id),
	KEY status_type_created (status, type, created_at),
	KEY customer_id (customer_id),
	KEY email_hash_created (customer_email_hash, created_at),
	KEY created_at (created_at)
) {$collate};
CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	request_id bigint(20) unsigned NOT NULL,
	order_item_id bigint(20) unsigned NOT NULL,
	product_id bigint(20) unsigned NOT NULL DEFAULT 0,
	variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
	quantity int(10) unsigned NOT NULL DEFAULT 0,
	reason_code varchar(50) DEFAULT NULL,
	line_total decimal(19,4) DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY request_id (request_id),
	KEY product_id (product_id)
) {$collate};";
	}
}
