<?php
/**
 * Signed order-link tokens.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

use PostPurchaseHub\Install\Activator;

/**
 * Issues and verifies the HMAC tokens that back guest order links.
 *
 * `payload = order_id|order_key|expiry`, `token = base64url(payload) . '.' .
 * hash_hmac('sha256', payload, $secret)`, exactly as fixed in docs/SPEC.md
 * Phase 8 — this is a wire format other systems (email templates, the guest
 * landing page) will parse, so it is not this class's decision to make twice.
 *
 * Deliberately idempotent within its TTL rather than one-time-burn: corporate
 * mail scanners and link previewers pre-fetch URLs, and a token that dies on
 * first GET turns a legitimate customer's link dead before they click it.
 * Nothing here creates a WP session or logs a browser in — a token grants
 * order-scoped read/action capability only, never a login.
 *
 * @since 0.6.0
 */
final class TokenService {

	/**
	 * Settings key for the configured TTL, in days.
	 *
	 * @var string
	 */
	public const TTL_SETTING = 'token_ttl_days';

	/**
	 * TTL used when the merchant has not configured one.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_DAYS = 14;

	/**
	 * TTL ceiling. Not configurable past this — a long-lived, idempotent,
	 * unauthenticated bearer credential is exactly the shape of thing that
	 * should not be allowed to drift toward "forever" through a settings field.
	 *
	 * @var int
	 */
	public const MAX_TTL_DAYS = 90;

	/**
	 * Number of `|`-delimited fields a payload must have.
	 *
	 * @var int
	 */
	private const PAYLOAD_FIELDS = 3;

	/**
	 * Issues a token bound to one order at its current order key.
	 *
	 * @since 0.6.0
	 *
	 * @param int      $order_id  Order id.
	 * @param string   $order_key Order's current order key. Rotating it invalidates every token issued against the old value.
	 * @param int|null $ttl_seconds Overrides the configured TTL for this token, still hard-capped. Null uses the configured/default TTL.
	 * @return string
	 * @throws \RuntimeException When no token secret has been installed yet.
	 */
	public function issue( int $order_id, string $order_key, ?int $ttl_seconds = null ): string {
		$secret = $this->secret();

		if ( '' === $secret ) {
			throw new \RuntimeException( esc_html( 'Token secret is not installed.' ) );
		}

		$expiry  = time() + ( $ttl_seconds ?? $this->ttl_seconds() );
		$payload = $order_id . '|' . $order_key . '|' . $expiry;

		return self::base64url_encode( $payload ) . '.' . hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Verifies a token's signature and expiry and returns what it asserts.
	 *
	 * Every failure — malformed shape, bad base64, bad signature, unparseable
	 * fields, an expired timestamp — returns `null`. There is no partial
	 * result: a caller either has a fully verified payload or nothing, and the
	 * order-key comparison against the *current* order is left to the caller
	 * (`OwnershipResolver`), because this class never loads an order.
	 *
	 * @since 0.6.0
	 *
	 * @param string $token Token as issued by issue().
	 * @return TokenPayload|null
	 */
	public function decode( string $token ): ?TokenPayload {
		$secret = $this->secret();

		if ( '' === $secret ) {
			return null;
		}

		$parts = explode( '.', $token );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		[ $encoded_payload, $signature ] = $parts;

		$payload = self::base64url_decode( $encoded_payload );

		if ( null === $payload ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$fields = explode( '|', $payload, self::PAYLOAD_FIELDS );

		if ( self::PAYLOAD_FIELDS !== count( $fields ) ) {
			return null;
		}

		[ $order_id, $order_key, $expiry ] = $fields;

		if ( 1 !== preg_match( '/^\d+$/', $order_id ) || 1 !== preg_match( '/^\d+$/', $expiry ) || '' === $order_key ) {
			return null;
		}

		if ( (int) $expiry < time() ) {
			return null;
		}

		return new TokenPayload( (int) $order_id, $order_key, (int) $expiry );
	}

	/**
	 * TTL to issue a new token with, in seconds.
	 *
	 * @since 0.6.0
	 *
	 * @return int
	 */
	public function ttl_seconds(): int {
		$settings = get_option( 'pph_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$days     = isset( $settings[ self::TTL_SETTING ] ) ? (int) $settings[ self::TTL_SETTING ] : self::DEFAULT_TTL_DAYS;

		/**
		 * Filters the signed-token TTL, in days, before the hard cap is applied.
		 *
		 * @since 0.6.0
		 *
		 * @param int $days Configured TTL in days.
		 */
		$days = (int) apply_filters( 'pph_token_ttl_days', $days );

		// The cap applies after the filter too: a filter raising this is a
		// merchant preference the plugin does not get to honour past 90 days.
		$days = max( 1, min( self::MAX_TTL_DAYS, $days ) );

		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Whether this install can mint tokens at all.
	 *
	 * The secret is written once, by `Install\Activator`. A site that has
	 * somehow lost it — the option deleted, a clone with options stripped, a
	 * plugin dropped into `mu-plugins` where no activation hook ever fires —
	 * can still verify nothing and issue nothing, and `issue()` throws rather
	 * than minting a token against an empty key. Callers that render a link as
	 * part of something larger ask this first, so a missing secret costs the
	 * link and not the whole email.
	 *
	 * @since 0.16.0
	 *
	 * @return bool
	 */
	public function has_secret(): bool {
		return '' !== $this->secret();
	}

	/**
	 * Reads the HMAC secret generated at activation.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function secret(): string {
		return (string) get_option( Activator::TOKEN_SECRET_OPTION, '' );
	}

	/**
	 * URL-safe base64 encoding, without padding.
	 *
	 * @since 0.6.0
	 *
	 * @param string $data Raw data.
	 * @return string
	 */
	private static function base64url_encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a token payload for URL transport, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decoding.
	 *
	 * @since 0.6.0
	 *
	 * @param string $data Encoded data.
	 * @return string|null Null when the input is not valid base64url.
	 */
	private static function base64url_decode( string $data ): ?string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $data ) ) {
			return null;
		}

		$padded = str_pad( $data, ( 4 - strlen( $data ) % 4 ) % 4 + strlen( $data ), '=' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a token payload for URL transport, not obfuscation.
		$decoded = base64_decode( strtr( $padded, '-_', '+/' ), true );

		return false === $decoded ? null : $decoded;
	}
}
