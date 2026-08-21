<?php
/**
 * Rate limiting for the plugin's abuse-prone endpoints.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

use PostPurchaseHub\Support\Cache;

/**
 * Fixed-window counters keyed by IP, by email hash and site-wide.
 *
 * Built on `Support\Cache::incr()` rather than a stored list of attempt
 * timestamps: a real sliding-window log would need an unbounded (or
 * explicitly capped-and-pruned) array per identity, which is exactly the
 * unbounded growth CLAUDE.md's hard rule 12 rules out for something this
 * high-volume. A fixed window that resets on expiry is what docs/SPEC.md
 * Phase 8 actually describes doing ("via Cache/transients"), and `Cache`
 * already is object-cache-aware with a transient fallback and touches no
 * `wp_options` row per attempt.
 *
 * Each caller picks its own `$bucket` (e.g. `'lookup'`, `'cancel_request'`)
 * so the same three dimensions can be reused, independently throttled, by
 * every feature that needs them.
 *
 * @since 0.6.0
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param Cache $cache Backing counter store.
	 */
	public function __construct( private Cache $cache ) {}

	/**
	 * Registers one attempt against an IP address and reports whether it is still allowed.
	 *
	 * @since 0.6.0
	 *
	 * @param string $bucket        Identifies the feature being limited.
	 * @param string $ip            Remote IP address.
	 * @param int    $limit         Attempts allowed within the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True while under the limit, false once exceeded.
	 */
	public function allow_ip( string $bucket, string $ip, int $limit, int $window_seconds ): bool {
		return $this->hit( $bucket . '_ip_' . hash( 'sha256', $ip ), $limit, $window_seconds );
	}

	/**
	 * Registers one attempt against an email address and reports whether it is still allowed.
	 *
	 * The address is normalised before hashing, so casing and dot variants of
	 * the same mailbox share one counter instead of each getting a fresh
	 * budget (docs/SPEC.md Phase 8's email-alias bypass concern).
	 *
	 * @since 0.6.0
	 *
	 * @param string $bucket         Identifies the feature being limited.
	 * @param string $email          Candidate email address.
	 * @param int    $limit          Attempts allowed within the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True while under the limit, false once exceeded.
	 */
	public function allow_email( string $bucket, string $email, int $limit, int $window_seconds ): bool {
		return $this->hit( $bucket . '_email_' . Sanitizer::hash_email( $email ), $limit, $window_seconds );
	}

	/**
	 * Registers one attempt against the site as a whole and reports whether it is still allowed.
	 *
	 * The backstop against a botnet spreading one attempt per IP and per
	 * email across enough identities to slip under both of those limits.
	 *
	 * @since 0.6.0
	 *
	 * @param string $bucket         Identifies the feature being limited.
	 * @param int    $limit          Attempts allowed within the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True while under the limit, false once exceeded.
	 */
	public function allow_site( string $bucket, int $limit, int $window_seconds ): bool {
		return $this->hit( $bucket . '_site', $limit, $window_seconds );
	}

	/**
	 * Increments a named counter and compares it against its limit.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key            Counter key, already unique to the bucket and dimension.
	 * @param int    $limit          Attempts allowed within the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool
	 */
	private function hit( string $key, int $limit, int $window_seconds ): bool {
		return $this->cache->incr( 'rl_' . $key, $window_seconds ) <= max( 1, $limit );
	}
}
