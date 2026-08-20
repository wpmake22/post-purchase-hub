<?php
/**
 * In-memory stand-in for the WordPress functions the unit suite needs.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

/**
 * Backing store for the shims in tests/stubs/wp-functions.php.
 *
 * The unit suite boots no WordPress, so the classes under test talk to this
 * instead. It mirrors the two behaviours the cache depends on: transients live
 * in the options table alongside a `_transient_timeout_` row, and object cache
 * entries carry their own expiry.
 *
 * @since 0.1.0
 */
final class FakeWordPress {

	/**
	 * Whether wp_using_ext_object_cache() should report a persistent backend.
	 *
	 * @var bool
	 */
	public static bool $ext_object_cache = false;

	/**
	 * Object cache entries: group => key => array{value: mixed, expires: int}.
	 *
	 * @var array<string, array<string, array{value: mixed, expires: int}>>
	 */
	public static array $object_cache = array();

	/**
	 * Option rows, including the transient rows WordPress keeps here.
	 *
	 * @var array<string, mixed>
	 */
	public static array $options = array();

	/**
	 * Hooks registered through add_action(): hook => list of callbacks.
	 *
	 * @var array<string, list<array{callback: mixed, priority: int}>>
	 */
	public static array $actions = array();

	/**
	 * Every TTL passed to set_transient(): name => list of seconds.
	 *
	 * @var array<string, list<int>>
	 */
	public static array $transient_writes = array();

	/**
	 * Clears all state between tests.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ext_object_cache = false;
		self::$object_cache     = array();
		self::$options          = array();
		self::$actions          = array();
		self::$transient_writes = array();
	}

	/**
	 * Transient names currently stored, without the WordPress prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public static function transient_names(): array {
		$names = array();

		foreach ( array_keys( self::$options ) as $option ) {
			if ( str_starts_with( $option, '_transient_' ) && ! str_starts_with( $option, '_transient_timeout_' ) ) {
				$names[] = substr( $option, strlen( '_transient_' ) );
			}
		}

		return $names;
	}

	/**
	 * Object cache keys currently stored in a group.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Cache group.
	 * @return list<string>
	 */
	public static function object_cache_keys( string $group ): array {
		return array_keys( self::$object_cache[ $group ] ?? array() );
	}
}
