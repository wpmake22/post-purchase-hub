<?php
/**
 * Namespaced, object-cache-aware cache with a transient fallback.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Support;

/**
 * Stores regenerable values only.
 *
 * Two backends: a persistent object cache when the site has one, transients
 * otherwise. WordPress's built-in object cache is per-request, so it is not
 * treated as a backend at all — anything cached in it would be recomputed on
 * every request while looking cached.
 *
 * Every key is namespaced with a generation number, which makes flush() a
 * single option write instead of an enumeration the object cache cannot do.
 * Nothing here is authoritative state: a flush must only ever cost work.
 *
 * @since 0.1.0
 */
final class Cache {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	public const GROUP = 'pph';

	/**
	 * Non-autoloaded option holding the current key generation.
	 *
	 * @var string
	 */
	public const GENERATION_OPTION = 'pph_cache_generation';

	/**
	 * Longest key we pass through verbatim.
	 *
	 * Transient names live in `option_name` (191 chars) behind the
	 * `_transient_timeout_` prefix, so the budget is 172 characters.
	 *
	 * @var int
	 */
	private const MAX_KEY_LENGTH = 120;

	/**
	 * Memoised generation number.
	 *
	 * @var int|null
	 */
	private ?int $generation = null;

	/**
	 * Reads a cached value.
	 *
	 * The transient backend cannot distinguish a stored `false` from a miss, so
	 * callers must not cache `false`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key           Unprefixed key.
	 * @param mixed  $default_value Returned on a miss.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		$name = $this->name( $key );

		if ( $this->using_object_cache() ) {
			$found = false;
			$value = wp_cache_get( $name, self::GROUP, false, $found );

			return $found ? $value : $default_value;
		}

		$value = get_transient( $name );

		return false === $value ? $default_value : $value;
	}

	/**
	 * Writes a cached value with an explicit expiry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   Unprefixed key.
	 * @param mixed  $value Value to store. Never `false`.
	 * @param int    $ttl   Lifetime in seconds. A non-positive TTL is a programming error and stores nothing.
	 * @return bool
	 */
	public function set( string $key, $value, int $ttl ): bool {
		if ( $ttl < 1 ) {
			return false;
		}

		$name = $this->name( $key );

		return $this->using_object_cache()
			? wp_cache_set( $name, $value, self::GROUP, $ttl )
			: set_transient( $name, $value, $ttl );
	}

	/**
	 * Deletes a cached value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Unprefixed key.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		$name = $this->name( $key );

		return $this->using_object_cache()
			? wp_cache_delete( $name, self::GROUP )
			: delete_transient( $name );
	}

	/**
	 * Increments a counter inside a fixed window and returns the new total.
	 *
	 * The window starts on the first increment and is not extended by later
	 * ones, so both backends expire a counter at the same moment — rate limits
	 * must not behave differently because a site added an object cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key  Unprefixed key.
	 * @param int    $ttl  Window length in seconds, applied when the window opens.
	 * @param int    $step Amount to add.
	 * @return int Counter value after the increment.
	 */
	public function incr( string $key, int $ttl, int $step = 1 ): int {
		$name = $this->name( $key );
		$ttl  = max( 1, $ttl );

		if ( $this->using_object_cache() ) {
			if ( false !== wp_cache_add( $name, $step, self::GROUP, $ttl ) ) {
				return $step;
			}

			$total = wp_cache_incr( $name, $step, self::GROUP );

			if ( false === $total ) {
				// The entry expired or was evicted between the add and the incr.
				wp_cache_set( $name, $step, self::GROUP, $ttl );

				return $step;
			}

			return (int) $total;
		}

		$current = get_transient( $name );

		if ( false === $current ) {
			set_transient( $name, $step, $ttl );

			return $step;
		}

		$total = (int) $current + $step;

		set_transient( $name, $total, $this->remaining_window( $name, $ttl ) );

		return $total;
	}

	/**
	 * Invalidates every value this cache has written.
	 *
	 * Bumps the generation rather than enumerating keys, because an external
	 * object cache cannot be enumerated at all.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$next = $this->generation() + 1;

		update_option( self::GENERATION_OPTION, $next, false );

		$this->generation = $next;
	}

	/**
	 * Builds the storage name for a key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Unprefixed key.
	 * @return string
	 */
	private function name( string $key ): string {
		$clean = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '_', $key ) );

		if ( strlen( $clean ) > self::MAX_KEY_LENGTH ) {
			$clean = substr( $clean, 0, self::MAX_KEY_LENGTH - 33 ) . '_' . md5( $key );
		}

		return 'pph_' . $this->generation() . '_' . $clean;
	}

	/**
	 * Seconds left in an open transient window.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Storage name.
	 * @param int    $ttl  Fallback window length.
	 * @return int
	 */
	private function remaining_window( string $name, int $ttl ): int {
		$expires   = (int) get_option( '_transient_timeout_' . $name, 0 );
		$remaining = $expires - time();

		return $remaining > 0 ? $remaining : $ttl;
	}

	/**
	 * Current key generation.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private function generation(): int {
		if ( null === $this->generation ) {
			$this->generation = max( 0, (int) get_option( self::GENERATION_OPTION, 0 ) );
		}

		return $this->generation;
	}

	/**
	 * Whether a persistent object cache is available.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function using_object_cache(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}
}
