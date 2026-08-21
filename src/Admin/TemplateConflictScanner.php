<?php
/**
 * Detection of theme overrides on the templates replacement mode would take over.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Support\Cache;

/**
 * Reports whether the active theme already owns the order templates.
 *
 * Replacement mode swaps two WooCommerce templates for this plugin's. On a
 * store whose theme has copied those files — which is most stores running a
 * commercial theme — that swap silently discards the theme's layout, and the
 * merchant's first sign of it is a customer complaint. So the swap is refused
 * while a conflict stands, and this is what decides.
 *
 * File inspection only detects file-based overrides. A page builder that
 * intercepts the account page as a widget leaves no template on disk and cannot
 * be found this way, which is why replacement stays opt-in even on a clean scan.
 *
 * @since 0.4.0
 */
final class TemplateConflictScanner {

	/**
	 * WooCommerce templates replacement mode would take over.
	 *
	 * @var string[]
	 */
	public const WATCHED = array(
		'myaccount/orders.php',
		'myaccount/view-order.php',
	);

	/**
	 * Cache key holding the scan result.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'template_conflicts';

	/**
	 * How long a scan is trusted, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Cache $cache Cache.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Drops the cached scan when the active theme changes.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'switch_theme', array( $this, 'forget' ) );
	}

	/**
	 * The overridden templates, as template name => absolute path.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $refresh Skip the cached answer and look again.
	 * @return array<string, string>
	 */
	public function conflicts( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = $this->cache->get( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$found = array();

		foreach ( self::WATCHED as $template ) {
			$path = locate_template( array( $this->woocommerce_path() . $template ) );

			if ( '' !== $path ) {
				$found[ $template ] = $path;
			}
		}

		$this->cache->set( self::CACHE_KEY, $found, self::TTL );

		return $found;
	}

	/**
	 * Whether the theme owns any of the watched templates.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $refresh Skip the cached answer and look again.
	 * @return bool
	 */
	public function has_conflicts( bool $refresh = false ): bool {
		return array() !== $this->conflicts( $refresh );
	}

	/**
	 * Drops the cached scan.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function forget(): void {
		$this->cache->delete( self::CACHE_KEY );
	}

	/**
	 * The directory a theme places WooCommerce overrides in.
	 *
	 * Read from WooCommerce rather than hardcoded, because a site can filter it.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	private function woocommerce_path(): string {
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'template_path' ) ) ) {
			return (string) WC()->template_path();
		}

		return 'woocommerce/';
	}
}
