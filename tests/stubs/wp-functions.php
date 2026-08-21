<?php
/**
 * WordPress function shims for the unit suite.
 *
 * The unit suite deliberately boots no WordPress (tests/bootstrap-unit.php), so
 * the handful of functions our pure-PHP classes call are defined here against
 * an in-memory store. Every shim is guarded, so loading this file where the
 * real WordPress exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- These shims must carry the WordPress names they replace.

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	/**
	 * Whether a persistent object cache is in use.
	 *
	 * @param bool|null $using Unused; present for signature parity.
	 * @return bool
	 */
	function wp_using_ext_object_cache( $using = null ): bool {
		unset( $using );

		return FakeWordPress::$ext_object_cache;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * Reads an object cache entry.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param bool   $force Unused.
	 * @param bool   $found Set to true when the key was present and unexpired.
	 * @return mixed
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		unset( $force );

		$entry = FakeWordPress::$object_cache[ $group ][ $key ] ?? null;

		if ( null === $entry || ( 0 !== $entry['expires'] && $entry['expires'] <= time() ) ) {
			unset( FakeWordPress::$object_cache[ $group ][ $key ] );
			$found = false;

			return false;
		}

		$found = true;

		return $entry['value'];
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * Writes an object cache entry.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $data   Value.
	 * @param string $group  Cache group.
	 * @param int    $expire Lifetime in seconds.
	 * @return bool
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool {
		FakeWordPress::$object_cache[ $group ][ $key ] = array(
			'value'   => $data,
			'expires' => $expire > 0 ? time() + (int) $expire : 0,
		);

		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * Writes an object cache entry only when the key is free.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $data   Value.
	 * @param string $group  Cache group.
	 * @param int    $expire Lifetime in seconds.
	 * @return bool
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool {
		$found = false;
		wp_cache_get( $key, $group, false, $found );

		if ( $found ) {
			return false;
		}

		return wp_cache_set( $key, $data, $group, $expire );
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	/**
	 * Increments a numeric object cache entry, keeping its original expiry.
	 *
	 * @param string $key    Cache key.
	 * @param int    $offset Amount to add.
	 * @param string $group  Cache group.
	 * @return int|false
	 */
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		$found = false;
		$value = wp_cache_get( $key, $group, false, $found );

		if ( ! $found || ! is_numeric( $value ) ) {
			return false;
		}

		$total = (int) $value + (int) $offset;

		FakeWordPress::$object_cache[ $group ][ $key ]['value'] = $total;

		return $total;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Deletes an object cache entry.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return bool
	 */
	function wp_cache_delete( $key, $group = '' ): bool {
		if ( ! isset( FakeWordPress::$object_cache[ $group ][ $key ] ) ) {
			return false;
		}

		unset( FakeWordPress::$object_cache[ $group ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads an option row.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Returned when the row is absent.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		return array_key_exists( $option, FakeWordPress::$options )
			? FakeWordPress::$options[ $option ]
			: $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Writes an option row.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Unused.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ): bool {
		unset( $autoload );

		FakeWordPress::$options[ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Reads a transient, honouring its expiry row.
	 *
	 * @param string $transient Transient name.
	 * @return mixed False on a miss.
	 */
	function get_transient( $transient ) {
		$timeout = FakeWordPress::$options[ '_transient_timeout_' . $transient ] ?? null;

		if ( null !== $timeout && (int) $timeout <= time() ) {
			unset(
				FakeWordPress::$options[ '_transient_' . $transient ],
				FakeWordPress::$options[ '_transient_timeout_' . $transient ]
			);
		}

		return FakeWordPress::$options[ '_transient_' . $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Writes a transient and its expiry row.
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds.
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration = 0 ): bool {
		FakeWordPress::$options[ '_transient_' . $transient ] = $value;

		if ( $expiration > 0 ) {
			FakeWordPress::$options[ '_transient_timeout_' . $transient ] = time() + (int) $expiration;
		}

		FakeWordPress::$transient_writes[ $transient ][] = (int) $expiration;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Deletes a transient and its expiry row.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	function delete_transient( $transient ): bool {
		if ( ! array_key_exists( '_transient_' . $transient, FakeWordPress::$options ) ) {
			return false;
		}

		unset(
			FakeWordPress::$options[ '_transient_' . $transient ],
			FakeWordPress::$options[ '_transient_timeout_' . $transient ]
		);

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records a hook registration.
	 *
	 * @param string $hook_name     Hook name.
	 * @param mixed  $callback      Callback.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Unused.
	 * @return bool
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): bool {
		unset( $accepted_args );

		FakeWordPress::$actions[ $hook_name ][] = array(
			'callback' => $callback,
			'priority' => (int) $priority,
		);

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers a filter callback.
	 *
	 * @param string $hook_name     Hook name.
	 * @param mixed  $callback      Callback.
	 * @param int    $priority      Unused; registration order is used instead.
	 * @param int    $accepted_args Unused.
	 * @return bool
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );

		FakeWordPress::$filters[ $hook_name ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Applies the registered callbacks to a value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) { // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- A shim, not a hook declaration.
		foreach ( FakeWordPress::$filters[ $hook_name ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapes text for HTML output.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the string unchanged; the unit suite loads no translations.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Returns the string unchanged; the unit suite loads no translations.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text    Text.
	 * @param string $context Disambiguating context.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function _x( $text, $context, $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $context, $domain );

		return (string) $text;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Removes an option row.
	 *
	 * @since 0.3.0
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ): bool {
		if ( ! array_key_exists( $option, FakeWordPress::$options ) ) {
			return false;
		}

		unset( FakeWordPress::$options[ $option ] );

		return true;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * Returns the fake orders matching the paging arguments.
	 *
	 * Only the arguments this plugin passes are honoured: everything here is
	 * already ordered by id ascending, so `limit` and `page` are the whole of it.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, mixed>
	 */
	function wc_get_orders( $args = array() ): array {
		$orders = array_values( FakeWordPress::$orders );

		if ( 'DESC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ) {
			$orders = array_reverse( $orders );
		}

		$limit = (int) ( $args['limit'] ?? 10 );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );

		return $limit < 0 ? $orders : array_slice( $orders, ( $page - 1 ) * $limit, $limit );
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Returns a fake order by id.
	 *
	 * @since 0.3.0
	 *
	 * @param int $order_id Order id.
	 * @return mixed False when unknown, matching WooCommerce.
	 */
	function wc_get_order( $order_id = 0 ) {
		return FakeWordPress::$orders[ (int) $order_id ] ?? false;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- These deliberately mirror WordPress core constant names.

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require_once __DIR__ . '/wc-classes.php';
