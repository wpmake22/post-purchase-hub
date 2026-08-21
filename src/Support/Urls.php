<?php
/**
 * Request-URL reconstruction.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Support;

/**
 * Rebuilds the URL of the page being viewed, safely.
 *
 * Two surfaces need this — the guest-context exchange and the lookup form's
 * post/redirect/get — and both redirect to what it returns, so a second copy of
 * the reasoning below is a second chance to get it wrong.
 *
 * The scheme and host come from `home_url()`, never from the `Host` header,
 * because a request can claim any host it likes and this value ends up in a
 * `Location`. Only the path and query are taken from the request.
 * `wp_safe_redirect()` would reject an off-site target anyway; this makes one
 * impossible to construct in the first place.
 *
 * @since 0.11.0
 */
final class Urls {

	/**
	 * The current request's URL, with the named query arguments removed.
	 *
	 * @since 0.11.0
	 *
	 * @param string[] $strip Query arguments to drop.
	 * @return string
	 */
	public static function current( array $strip = array() ): string {
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		$path  = (string) wp_parse_url( $requested, PHP_URL_PATH );
		$query = (string) wp_parse_url( $requested, PHP_URL_QUERY );

		$home   = (string) home_url();
		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $home, PHP_URL_HOST );

		$url = $scheme . '://' . $host . ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );

		return array() === $strip ? $url : (string) remove_query_arg( $strip, $url );
	}
}
