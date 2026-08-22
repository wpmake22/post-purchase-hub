<?php
/**
 * Deactivation routine.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

use PostPurchaseHub\Support\Cache;

/**
 * Stands the plugin down without losing anything a merchant would miss.
 *
 * Deactivation is reversible by definition: it clears scheduled work and
 * regenerable caches, and nothing else. Options, tables and order meta survive
 * until uninstall, where deletion is opt-in.
 *
 * @since 0.1.0
 */
final class Deactivator {

	/**
	 * Every cron hook this plugin can schedule.
	 *
	 * Listed rather than discovered so a renamed hook has to be renamed here
	 * too, instead of silently leaving an orphaned event behind.
	 *
	 * @var string[]
	 */
	private const CRON_HOOKS = array( Activator::CLEANUP_HOOK, Activator::DIGEST_HOOK );

	/**
	 * Runs on deactivation.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Invalidates entries in a persistent object cache, which cannot be enumerated.
		( new Cache() )->flush();

		self::delete_transient_rows();
	}

	/**
	 * Removes this plugin's transient rows from the options table.
	 *
	 * The generation bump above makes stale entries unreadable, but on sites
	 * without an object cache the rows themselves would sit in `wp_options`
	 * until WordPress's expiry sweep, which is not soon enough for a plugin
	 * that writes one row per rate-limit window.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function delete_transient_rows(): void {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients cannot be enumerated through the options API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_pph_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_pph_' ) . '%'
			)
		);
	}
}
