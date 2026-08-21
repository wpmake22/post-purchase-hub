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
use PostPurchaseHub\Tests\Unit\Support\WPDieException;

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

if ( ! function_exists( '_n' ) ) {
	/**
	 * Returns the singular or plural form unchanged; the unit suite loads no translations.
	 *
	 * @since 0.8.0
	 *
	 * @param string $single The singular form.
	 * @param string $plural The plural form.
	 * @param int    $number Number to compare against, English pluralisation rules.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $domain );

		return 1 === (int) $number ? (string) $single : (string) $plural;
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

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Returns the string unchanged; the unit suite loads no translations.
	 *
	 * @since 0.4.0
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $domain );

		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'locate_template' ) ) {
	/**
	 * Resolves a template name against the fake theme.
	 *
	 * @since 0.4.0
	 *
	 * @param string|string[] $template_names Template names.
	 * @return string Empty string when the theme has none of them.
	 */
	function locate_template( $template_names ): string {
		foreach ( (array) $template_names as $name ) {
			if ( isset( FakeWordPress::$theme_templates[ $name ] ) ) {
				return FakeWordPress::$theme_templates[ $name ];
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Formats a timestamp in UTC; the unit suite has no site timezone.
	 *
	 * @since 0.4.0
	 *
	 * @param string   $format    Date format.
	 * @param int|null $timestamp Unix timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ): string {
		return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'is_account_page' ) ) {
	/**
	 * Whether the request is the My Account page.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	function is_account_page(): bool {
		return FakeWordPress::$is_account_page;
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * Whether the request is a given WooCommerce endpoint.
	 *
	 * @since 0.4.0
	 *
	 * @param string|false $endpoint Endpoint name.
	 * @return bool
	 */
	function is_wc_endpoint_url( $endpoint = false ): bool {
		return in_array( (string) $endpoint, FakeWordPress::$endpoints, true );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Returns the fake queried post.
	 *
	 * @since 0.4.0
	 * @return mixed
	 */
	function get_post() {
		return FakeWordPress::$post;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	/**
	 * Whether content contains a shortcode.
	 *
	 * @since 0.4.0
	 *
	 * @param string $content Content.
	 * @param string $tag     Shortcode tag.
	 * @return bool
	 */
	function has_shortcode( $content, $tag ): bool {
		return str_contains( (string) $content, '[' . (string) $tag );
	}
}

if ( ! function_exists( 'has_block' ) ) {
	/**
	 * Whether a post contains a block.
	 *
	 * @since 0.4.0
	 *
	 * @param string $name Block name.
	 * @param mixed  $post Post object.
	 * @return bool
	 */
	function has_block( $name, $post = null ): bool {
		$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';

		return str_contains( $content, '<!-- wp:' . (string) $name );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strips markup, closely enough to wp_strip_all_tags() for the unit suite:
	 * script/style blocks are removed with their content, everything else with
	 * strip_tags(), and the result is trimmed.
	 *
	 * @since 0.6.0
	 *
	 * @param string $text          Text.
	 * @param bool   $remove_breaks Whether to collapse whitespace, matching core's second argument.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This shim is what stands in for wp_strip_all_tags() itself in the unit suite.
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Whether the fake current user has a capability.
	 *
	 * @since 0.6.0
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ): bool {
		return in_array( (string) $capability, FakeWordPress::$current_user_capabilities, true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Returns the fake current user id.
	 *
	 * @since 0.4.0
	 * @return int
	 */
	function get_current_user_id(): int {
		return FakeWordPress::$current_user_id;
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Merges shortcode attributes over their defaults.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $pairs     Defaults.
	 * @param array<string, mixed> $atts      Supplied attributes.
	 * @param string               $shortcode Shortcode tag.
	 * @return array<string, mixed>
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ): array {
		unset( $shortcode );

		$out = array();

		foreach ( (array) $pairs as $name => $default_value ) {
			$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default_value;
		}

		return $out;
	}
}

if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	/**
	 * Returns the WooCommerce core order statuses, prefixed.
	 *
	 * @since 0.4.0
	 * @return array<string, string>
	 */
	function wc_get_order_statuses(): array {
		return array(
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-on-hold'    => 'On hold',
			'wc-completed'  => 'Completed',
			'wc-cancelled'  => 'Cancelled',
			'wc-refunded'   => 'Refunded',
			'wc-failed'     => 'Failed',
		);
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escapes text for an HTML attribute.
	 *
	 * @since 0.4.0
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escapes a URL for output.
	 *
	 * @since 0.4.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Echoes an escaped translated string.
	 *
	 * @since 0.4.0
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ): void { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $domain );

		echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the line above.
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Echoes an escaped translated string for an attribute context.
	 *
	 * @since 0.7.0
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_attr_e( $text, $domain = 'default' ): void { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
		unset( $domain );

		echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the line above.
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Runs the callbacks registered for an action.
	 *
	 * @since 0.4.0
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ): void { // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- A shim, not a hook declaration.
		foreach ( FakeWordPress::$actions[ $hook_name ] ?? array() as $action ) {
			if ( is_callable( $action['callback'] ) ) {
				call_user_func_array( $action['callback'], $args );
			}
		}
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	/**
	 * Returns the fake WooCommerce page id.
	 *
	 * @since 0.4.1
	 *
	 * @param string $page Page slug.
	 * @return int
	 */
	function wc_get_page_id( $page ): int {
		return 'myaccount' === $page ? FakeWordPress::$account_page_id : 0;
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	/**
	 * Returns a fake WooCommerce logger that records every call.
	 *
	 * @since 0.8.0
	 * @return WC_Logger_Interface
	 */
	function wc_get_logger(): WC_Logger_Interface {
		return new class() implements WC_Logger_Interface {
			/**
			 * Records the call in the shared fake WordPress state.
			 *
			 * @param string               $level   Log level.
			 * @param string               $message Message.
			 * @param array<string, mixed> $context Additional context.
			 * @return void
			 */
			public function log( $level, $message, $context = array() ) {
				FakeWordPress::$logged[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		};
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Returns a fake post meta value.
	 *
	 * @since 0.4.1
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $single );

		return FakeWordPress::$post_meta[ (int) $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	/**
	 * Returns a fake post field.
	 *
	 * @since 0.4.1
	 *
	 * @param string $field   Field name.
	 * @param int    $post_id Post id.
	 * @return string
	 */
	function get_post_field( $field, $post_id = 0 ): string {
		return 'post_content' === $field ? ( FakeWordPress::$post_content[ (int) $post_id ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Casts to a non-negative integer.
	 *
	 * @since 0.8.0
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strips tags and collapses whitespace, closely enough to sanitize_text_field()
	 * for the unit suite.
	 *
	 * @since 0.8.0
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ): string {
		$value = wp_strip_all_tags( (string) $value );
		$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( $value );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Whether the fake current user is logged in.
	 *
	 * @since 0.8.0
	 * @return bool
	 */
	function is_user_logged_in(): bool {
		return FakeWordPress::$current_user_id > 0;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * No-op stand-in; the unit suite never feeds this slashed input.
	 *
	 * @since 0.8.0
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Records a REST route registration.
	 *
	 * @since 0.8.0
	 *
	 * @param string               $namespace Route namespace.
	 * @param string               $route     Route pattern.
	 * @param array<string, mixed> $args      Route arguments.
	 * @return bool
	 */
	function register_rest_route( $namespace, $route, $args = array() ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Matches WordPress core's own parameter name.
		FakeWordPress::$rest_routes[] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		);

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Records a style enqueue.
	 *
	 * @since 0.8.0
	 *
	 * @param string             $handle Style handle.
	 * @param string             $src    Style URL.
	 * @param array<int, string> $deps   Dependencies.
	 * @param string|null        $ver    Version.
	 * @return void
	 */
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ): void {
		FakeWordPress::$enqueued_styles[ $handle ] = array(
			'src'  => $src,
			'deps' => $deps,
			'ver'  => $ver,
		);
	}
}

if ( ! function_exists( 'wp_style_add_data' ) ) {
	/**
	 * Records a style's extra data.
	 *
	 * @since 0.8.0
	 *
	 * @param string $handle Style handle.
	 * @param string $key    Data key.
	 * @param mixed  $value  Data value.
	 * @return bool
	 */
	function wp_style_add_data( $handle, $key, $value ): bool {
		FakeWordPress::$style_data[ $handle ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Records a script enqueue.
	 *
	 * @since 0.8.0
	 *
	 * @param string             $handle    Script handle.
	 * @param string             $src       Script URL.
	 * @param array<int, string> $deps      Dependencies.
	 * @param string|bool|null   $ver       Version.
	 * @param bool               $in_footer Whether to print in the footer.
	 * @return void
	 */
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ): void {
		FakeWordPress::$enqueued_scripts[ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Records data localised to a script.
	 *
	 * @since 0.8.0
	 *
	 * @param string               $handle      Script handle.
	 * @param string               $object_name JS global name.
	 * @param array<string, mixed> $data        Data.
	 * @return bool
	 */
	function wp_localize_script( $handle, $object_name, $data ): bool {
		FakeWordPress::$localized_scripts[ $handle ][ $object_name ] = $data;

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Builds a fake REST URL.
	 *
	 * @since 0.8.0
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	function rest_url( $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Builds a deterministic fake nonce.
	 *
	 * @since 0.8.0
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ): string {
		return 'nonce-for-' . (string) $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Verifies a nonce built by the wp_create_nonce() shim above.
	 *
	 * @since 0.9.0
	 *
	 * @param string $nonce  Nonce to verify.
	 * @param string $action Nonce action.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return ( wp_create_nonce( $action ) === (string) $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Throws instead of halting, so a unit test can observe what a real
	 * wp_die() call would have done.
	 *
	 * @since 0.9.0
	 *
	 * @param string|\WP_Error         $message Message.
	 * @param string                   $title   Unused by this shim.
	 * @param array<string, mixed>|int $args    Args, honouring `response` as the HTTP status.
	 * @return never
	 * @throws WPDieException Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		unset( $title );

		$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 200;

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This shim stands in for wp_die() itself; nothing here echoes, and WPDieException carries the message for a test to assert on, not for output.
		throw new WPDieException( is_string( $message ) ? $message : '', $status );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Builds a fake wp-admin URL.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Path relative to wp-admin/.
	 * @return string
	 */
	function admin_url( $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Builds a fake site URL.
	 *
	 * @since 0.10.0
	 *
	 * @param string $path Path relative to the site root.
	 * @return string
	 */
	function home_url( $path = '/' ): string {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Adds or replaces query args on a URL, as WordPress's own does for the
	 * two-argument (`$key`, `$value`, `$url`) and array-argument forms this
	 * codebase uses.
	 *
	 * @since 0.10.0
	 *
	 * @param array<string, mixed>|string $key   Query arg name, or an associative array of args.
	 * @param mixed                       $value Query arg value, or the URL when $key is an array.
	 * @param string|null                 $url   URL to add args to. Required unless $key is an array.
	 * @return string
	 */
	function add_query_arg( $key, $value = null, $url = null ): string {
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = (string) $value;
		} else {
			$args = array( (string) $key => $value );
			$url  = (string) $url;
		}

		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		$query     = array();

		foreach ( $args as $arg_key => $arg_value ) {
			$query[] = rawurlencode( (string) $arg_key ) . '=' . rawurlencode( (string) $arg_value );
		}

		return $url . $separator . implode( '&', $query );
	}
}

if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	/**
	 * Fakes WooCommerce's page-permalink lookup.
	 *
	 * @since 0.10.0
	 *
	 * @param string $page Page id, e.g. 'myaccount'.
	 * @return string
	 */
	function wc_get_page_permalink( $page ): string {
		return 'https://example.test/' . sanitize_key( (string) $page ) . '/';
	}
}

if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
	/**
	 * Fakes WooCommerce's account-endpoint URL builder.
	 *
	 * @since 0.10.0
	 *
	 * @param string $endpoint Endpoint slug, e.g. 'view-order'.
	 * @param string $value    Endpoint value, e.g. the order id.
	 * @param string $permalink Base permalink the endpoint is appended to.
	 * @return string
	 */
	function wc_get_endpoint_url( $endpoint, $value = '', $permalink = '' ): string {
		$url = '' === $permalink ? home_url( '/' ) : $permalink;

		return trailingslashit( $url ) . $endpoint . '/' . rawurlencode( (string) $value ) . '/';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * The active locale: the top of the switch_to_locale() stack, or the site's.
	 *
	 * @since 0.10.0
	 * @return string
	 */
	function get_locale(): string {
		$top = end( FakeWordPress::$locale_stack );

		return false !== $top ? $top : FakeWordPress::$site_locale;
	}
}

if ( ! function_exists( 'get_user_locale' ) ) {
	/**
	 * A fake user's own locale, defaulting to the site locale when unseeded.
	 *
	 * @since 0.10.0
	 *
	 * @param int|\WP_User $user_id User id, or 0 for the current user.
	 * @return string
	 */
	function get_user_locale( $user_id = 0 ): string {
		$id = is_object( $user_id ) ? 0 : (int) $user_id;

		return FakeWordPress::$user_locales[ $id ] ?? FakeWordPress::$site_locale;
	}
}

if ( ! function_exists( 'switch_to_locale' ) ) {
	/**
	 * Pushes a locale, mirroring WP_Locale_Switcher::switch_to_locale(): a
	 * no-op that returns false when the target already is the active locale.
	 *
	 * @since 0.10.0
	 *
	 * @param string $locale Locale to switch to.
	 * @return bool
	 */
	function switch_to_locale( $locale ): bool {
		if ( get_locale() === $locale ) {
			return false;
		}

		FakeWordPress::$locale_stack[] = (string) $locale;

		return true;
	}
}

if ( ! function_exists( 'restore_current_locale' ) ) {
	/**
	 * Pops the most recently switched locale.
	 *
	 * @since 0.10.0
	 * @return string|false
	 */
	function restore_current_locale() {
		if ( array() === FakeWordPress::$locale_stack ) {
			return false;
		}

		return array_pop( FakeWordPress::$locale_stack );
	}
}

if ( ! function_exists( 'wc_get_template' ) ) {
	/**
	 * Records a template render rather than including a file — none of the
	 * real `templates/emails/` files are reachable from the unit suite, and
	 * rendering them is exercised in the integration suite instead.
	 *
	 * @since 0.10.0
	 *
	 * @param string               $template_name Template name.
	 * @param array<string, mixed> $args          Arguments passed to the template.
	 * @param string               $template_path Template path, unused by the shim.
	 * @param string               $default_path  Default path, unused by the shim.
	 * @return void
	 */
	function wc_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ): void {
		unset( $template_path, $default_path );

		FakeWordPress::$rendered_templates[] = array(
			'name' => (string) $template_name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Thin wrapper matching WordPress's own, close enough for the plain
	 * https:// URLs this plugin builds.
	 *
	 * @since 0.10.0
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* component, or -1 for all.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This shim is what wp_parse_url() delegates to.
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Appends exactly one trailing slash, as WordPress's own does.
	 *
	 * @since 0.10.0
	 *
	 * @param string $value Value to slash.
	 * @return string
	 */
	function trailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Records a redirect rather than sending one.
	 *
	 * @since 0.9.0
	 *
	 * @param string $location Destination URL.
	 * @param int    $status   HTTP status.
	 * @return bool
	 */
	function wp_safe_redirect( $location, $status = 302 ): bool {
		FakeWordPress::$redirects[] = array(
			'location' => (string) $location,
			'status'   => (int) $status,
		);

		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Lower-cases and strips anything but [a-z0-9_-], as sanitize_key() does.
	 *
	 * @since 0.9.0
	 *
	 * @param string $key Candidate key.
	 * @return string
	 */
	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Returns a fake user, if one was seeded.
	 *
	 * @since 0.9.0
	 *
	 * @param int $user_id User id.
	 * @return WP_User|false
	 */
	function get_userdata( $user_id ) {
		return FakeWordPress::$users[ (int) $user_id ] ?? false;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Records a submenu page registration.
	 *
	 * @since 0.9.0
	 *
	 * @param string   $parent_slug Parent menu slug.
	 * @param string   $page_title  Page title.
	 * @param string   $menu_title  Menu title.
	 * @param string   $capability  Required capability.
	 * @param string   $menu_slug   Page slug.
	 * @param callable $callback    Render callback.
	 * @return string|false
	 */
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
		FakeWordPress::$submenus[] = array(
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'callback'    => $callback,
		);

		return $parent_slug . '_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_meta_box' ) ) {
	/**
	 * Records a metabox registration.
	 *
	 * @since 0.9.0
	 *
	 * @param string   $id       Metabox id.
	 * @param string   $title    Title.
	 * @param callable $callback Render callback.
	 * @param mixed    $screen   Screen id or ids.
	 * @param string   $context  Context.
	 * @return void
	 */
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced' ): void {
		FakeWordPress::$meta_boxes[] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'screen'   => $screen,
			'context'  => $context,
		);
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Echoes/returns the `selected` HTML attribute when two values match, as
	 * WordPress's own selected() does.
	 *
	 * @since 0.9.0
	 *
	 * @param mixed $selected Current value.
	 * @param mixed $current  Value being compared against.
	 * @param bool  $display  Whether to echo the result.
	 * @return string
	 */
	function selected( $selected, $current = true, $display = true ): string {
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';

		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed string, never user input.
		}

		return $result;
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	/**
	 * A fixed "X hours"-shaped string; the unit suite does not assert on the
	 * exact wording, only that the age column renders something.
	 *
	 * @since 0.9.0
	 *
	 * @param int      $from Earlier timestamp.
	 * @param int|null $to   Later timestamp, now when omitted.
	 * @return string
	 */
	function human_time_diff( $from, $to = null ): string {
		$to = $to ?? time();

		return round( abs( $to - $from ) / HOUR_IN_SECONDS ) . ' hours';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Echoes a hidden nonce field built from the wp_create_nonce() shim.
	 *
	 * @since 0.9.0
	 *
	 * @param string $action  Nonce action.
	 * @param string $name    Field name.
	 * @param bool   $referer Whether to also print a referer field.
	 * @param bool   $display Whether to echo rather than return.
	 * @return string
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ): string {
		$field = sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( wp_create_nonce( $action ) ) );

		if ( $referer ) {
			$field .= '<input type="hidden" name="_wp_http_referer" value="">';
		}

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built entirely from esc_attr() above.
		}

		return $field;
	}
}

if ( ! function_exists( 'wc_increase_stock_levels' ) ) {
	/**
	 * Records a restock call rather than touching any product.
	 *
	 * @since 0.9.0
	 *
	 * @param \WC_Order|int $order Order or order id.
	 * @return void
	 */
	function wc_increase_stock_levels( $order ): void {
		FakeWordPress::$restocked_orders[] = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
	}
}

if ( ! function_exists( 'wc_create_refund' ) ) {
	/**
	 * A spy: this plugin must never call it. Records that it was called
	 * rather than returning anything a caller could mistake for a real
	 * refund object.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $args Unused.
	 * @return void
	 */
	function wc_create_refund( $args = array() ): void {
		unset( $args );

		++FakeWordPress::$refund_calls;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- These deliberately mirror WordPress core constant names.

/*
 * Templates guard on ABSPATH and call exit when it is absent, which would end
 * the PHPUnit process rather than fail a test.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
}

if ( ! defined( 'PPH_PLUGIN_DIR' ) ) {
	define( 'PPH_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'PPH_PLUGIN_URL' ) ) {
	define( 'PPH_PLUGIN_URL', 'https://example.test/wp-content/plugins/post-purchase-hub/' );
}

if ( ! defined( 'PPH_VERSION' ) ) {
	define( 'PPH_VERSION', '0.0.0-test' );
}

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

require_once __DIR__ . '/wp-post.php';
require_once __DIR__ . '/wp-user.php';
require_once __DIR__ . '/wp-error.php';
require_once __DIR__ . '/wp-rest-request.php';
require_once __DIR__ . '/wp-rest-response.php';
require_once __DIR__ . '/wc-logger.php';
require_once __DIR__ . '/wc-classes.php';
