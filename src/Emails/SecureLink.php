<?php
/**
 * Builds the URL a signed order-link token travels in.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Security\TokenService;

/**
 * One shared place to turn a token into a URL, used by both
 * `SecureOrderLink` (this milestone's dedicated email) and `LinkInjector`
 * (the opt-in link inside other Woo transactional emails) — so the two never
 * drift into building the URL differently.
 *
 * The URL points at the account's `view-order` endpoint with the token as a
 * `wpmphub_token` query argument. Nothing in this milestone reads that argument
 * back: `Frontend\GuestContext` (docs/SPEC.md Milestone 11) is what will
 * exchange it for a short-lived cookie-bound context and strip it from the
 * URL. Until that lands, this class only has to produce a link that decodes
 * to the right order and expires on schedule — both verified in this
 * milestone's tests without needing M11's landing page to exist.
 *
 * @since 0.10.0
 */
final class SecureLink {

	/**
	 * Query argument the token travels in.
	 *
	 * @var string
	 */
	public const TOKEN_PARAM = 'wpmphub_token';

	/**
	 * Builds a signed link to one order's account page.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order    $order        Order to link to.
	 * @param TokenService $tokens       Issues the token.
	 * @param int|null     $ttl_seconds  Overrides the configured TTL. Null uses the configured/default TTL.
	 * @return string
	 */
	public static function url( \WC_Order $order, TokenService $tokens, ?int $ttl_seconds = null ): string {
		$token = $tokens->issue( $order->get_id(), (string) $order->get_order_key(), $ttl_seconds );

		$endpoint = wc_get_endpoint_url( 'view-order', (string) $order->get_id(), wc_get_page_permalink( 'myaccount' ) );

		return add_query_arg( self::TOKEN_PARAM, $token, $endpoint );
	}
}
