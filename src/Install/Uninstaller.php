<?php
/**
 * Retention-aware teardown.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

/**
 * Removes this plugin's data, but only when the merchant has asked for it.
 *
 * The setting defaults to off. Deleting a store's cancellation and return
 * history because someone deactivated a plugin for ten minutes is not a
 * recoverable mistake, so the destructive path has to be chosen deliberately.
 *
 * Order meta is removed through the CRUD layer, which is the only way to touch
 * both HPOS and legacy storage identically. That makes it the slow part: a
 * plugin-deletion request cannot be allowed to run out of time part way, so the
 * sweep is bounded by orders and by seconds, and whatever is left is reported.
 * Meta left behind is inert — nothing reads `_pph_*` once the plugin is gone —
 * and `wp pph cleanup --order-meta` finishes the job on a large store.
 *
 * @since 0.2.0
 */
final class Uninstaller {

	/**
	 * Settings key that authorises deletion.
	 *
	 * @var string
	 */
	public const SETTING = 'delete_data_on_uninstall';

	/**
	 * Prefix every order meta key this plugin owns carries.
	 *
	 * @var string
	 */
	public const ORDER_META_PREFIX = '_pph_';

	/**
	 * Orders loaded per batch.
	 *
	 * @var int
	 */
	public const ORDER_BATCH = 100;

	/**
	 * Most orders one pass will scan.
	 *
	 * @var int
	 */
	public const MAX_ORDERS = 20000;

	/**
	 * Seconds one pass may spend scanning orders.
	 *
	 * @var int
	 */
	public const TIME_BUDGET = 15;

	/**
	 * Deletes everything when the setting allows it, otherwise nothing.
	 *
	 * @since 0.2.0
	 *
	 * @return bool Whether data was deleted.
	 */
	public static function run(): bool {
		if ( ! self::deletion_allowed() ) {
			return false;
		}

		self::delete_all();

		return true;
	}

	/**
	 * Whether the merchant opted into data deletion.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function deletion_allowed(): bool {
		$settings = get_option( 'pph_settings', array() );

		return is_array( $settings ) && ! empty( $settings[ self::SETTING ] );
	}

	/**
	 * Removes tables, options, order meta, transients and scheduled events.
	 *
	 * @since 0.2.0
	 *
	 * @return array{orders_scanned: int, orders_cleaned: int, remaining: bool, options: int}
	 */
	public static function delete_all(): array {
		$meta = self::delete_order_meta();

		// Before the options are deleted: this bumps the cache generation option,
		// which would otherwise be written back after the sweep had removed it.
		Deactivator::deactivate();

		Schema::drop();

		return array_merge( $meta, array( 'options' => self::delete_options() ) );
	}

	/**
	 * Deletes `_pph_*` meta from orders, in batches, through the CRUD layer.
	 *
	 * @since 0.2.0
	 *
	 * @param int $batch_size  Orders per batch.
	 * @param int $max_orders   Most orders to scan.
	 * @param int $time_budget  Seconds to spend.
	 * @return array{orders_scanned: int, orders_cleaned: int, remaining: bool}
	 */
	public static function delete_order_meta( int $batch_size = self::ORDER_BATCH, int $max_orders = self::MAX_ORDERS, int $time_budget = self::TIME_BUDGET ): array {
		$batch_size = max( 1, $batch_size );
		$scanned    = 0;
		$cleaned    = 0;
		$deadline   = time() + max( 1, $time_budget );
		$page       = 1;
		$more       = true;

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'orders_scanned' => 0,
				'orders_cleaned' => 0,
				'remaining'      => true,
			);
		}

		while ( $more && $scanned < $max_orders && time() < $deadline ) {
			$ids = wc_get_orders(
				array(
					'limit'   => $batch_size,
					'page'    => $page,
					'type'    => 'shop_order',
					'status'  => 'any',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);

			$ids  = is_array( $ids ) ? $ids : array();
			$more = count( $ids ) === $batch_size;

			foreach ( $ids as $id ) {
				++$scanned;

				$order_id = $id instanceof \WC_Order ? $id->get_id() : (int) $id;

				if ( self::strip_meta( $order_id ) ) {
					++$cleaned;
				}
			}

			++$page;
		}

		return array(
			'orders_scanned' => $scanned,
			'orders_cleaned' => $cleaned,
			'remaining'      => $more,
		);
	}

	/**
	 * Removes this plugin's meta from one order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether the order was changed.
	 */
	private static function strip_meta( int $order_id ): bool {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$changed = false;

		foreach ( $order->get_meta_data() as $meta ) {
			$key = isset( $meta->key ) ? (string) $meta->key : '';

			if ( '' !== $key && str_starts_with( $key, self::ORDER_META_PREFIX ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}

		return $changed;
	}

	/**
	 * Deletes every `pph_*` option through the options API.
	 *
	 * @since 0.2.0
	 *
	 * @return int Options removed.
	 */
	private static function delete_options(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options cannot be enumerated by prefix through the API.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'pph_' ) . '%'
			)
		);

		$removed = 0;

		foreach ( (array) $names as $name ) {
			if ( delete_option( (string) $name ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}
