<?php
/**
 * Page-cache plugin compatibility.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Compat;

use PostPurchaseHub\Frontend\GuestContext;

/**
 * Keeps a guest's order page out of every page cache on the store.
 *
 * `Security\Sanitizer::nocache()` defines `DONOTCACHEPAGE` and sends a
 * `Cache-Control` header, which is enough for a cache that consults PHP. The
 * problem is the caches that do not: a full-page cache answers a request from
 * disk or from an nginx rule before WordPress is loaded at all, so by the time
 * any of this plugin's code could object, a previous visitor's order page has
 * already been served to somebody else.
 *
 * The control that actually works is the context cookie. Every serious page
 * cache supports a list of cookies whose presence disables caching, so
 * registering ours there means a request carrying a guest context is never
 * answered from a cache and never written to one. That is a different mechanism
 * from `nocache()` and both are needed: the cookie list covers the requests
 * that never reach PHP, `nocache()` covers the ones that do.
 *
 * Each hook below is another plugin's, called only when that plugin is present,
 * and nothing here writes to another plugin's data (CLAUDE.md hard rule 9).
 *
 * @since 0.11.0
 */
final class PageCache {

	/**
	 * Wires the compatibility filters.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rocket_cache_reject_cookies', array( $this, 'add_cookie' ) );
		add_filter( 'litespeed_vary_cookies', array( $this, 'add_cookie' ) );
		add_filter( 'w3tc_pgcache_reject_cookies', array( $this, 'add_cookie' ) );
	}

	/**
	 * Adds this plugin's context cookie to a cache plugin's exclusion list.
	 *
	 * One callback for all three because all three ask the same question and
	 * take the same answer: a flat array of cookie names.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $cookies Cookie names the cache plugin already excludes.
	 * @return array<int, string>
	 */
	public function add_cookie( $cookies ): array {
		$cookies = is_array( $cookies ) ? array_values( $cookies ) : array();

		if ( ! in_array( GuestContext::COOKIE, $cookies, true ) ) {
			$cookies[] = GuestContext::COOKIE;
		}

		return $cookies;
	}
}
