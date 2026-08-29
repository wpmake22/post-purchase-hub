<?php
/**
 * Exchanges a signed order-link token for a cookie-bound context.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Security\Sanitizer;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Support\Urls;

/**
 * Takes the token out of the URL on the first navigation and keeps it server-side.
 *
 * A signed token is a bearer credential. Left in the query string it is copied
 * into the web server's access log, the `Referer` header of every outbound link
 * on the page, browser history, and any analytics script the store runs — so
 * docs/SPEC.md Phase 8 requires the landing page to swap it for something
 * bound to the visitor's browser and then redirect. What the visitor holds
 * afterwards is a random 32-character id in an HttpOnly cookie; the token
 * itself lives in this plugin's cache under a hash of that id, and expires on
 * its own.
 *
 * **This writes on a GET, which CLAUDE.md hard rule 4 otherwise forbids.** The
 * exception is deliberate and is the behaviour Phase 8 specifies: nothing about
 * an order, a request or any other domain record changes. What is written is a
 * self-expiring credential mapping and a cookie — session establishment, which
 * is the one thing a landing page cannot do without. No order is loaded, no
 * status moves, and nothing here is authoritative: losing the whole cache costs
 * a visitor one more click of their emailed link.
 *
 * **The exchange stays idempotent within the token's TTL.** Corporate mail
 * scanners and link previewers fetch URLs out of emails before the recipient
 * ever sees them (risk T9). Each fetch mints a context against its own cookie
 * jar and throws it away; because the token is not burned, the customer's own
 * click still works. A one-time token here would mean every scanned email
 * arrived pre-broken.
 *
 * Access itself is still decided in exactly one place: this class only supplies
 * the token through `wpmphub_current_request_token`, and
 * `Security\OwnershipResolver` remains the thing that compares it to an order.
 *
 * @since 0.11.0
 */
final class GuestContext {

	/**
	 * Cookie holding the opaque context id.
	 *
	 * @var string
	 */
	public const COOKIE = 'wpmphub_guest_context';

	/**
	 * Query argument set on the redirect, so a browser that refused the cookie
	 * can be told why rather than silently denied.
	 *
	 * @var string
	 */
	public const STATE_PARAM = 'wpmphub_context';

	/**
	 * Value of the state argument after a successful exchange.
	 *
	 * @var string
	 */
	public const STATE_READY = 'ready';

	/**
	 * Value of the state argument when the token did not verify.
	 *
	 * @var string
	 */
	public const STATE_EXPIRED = 'expired';

	/**
	 * Cache-key prefix for the id-to-token mapping.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'guest_ctx_';

	/**
	 * How long a context lives, regardless of how long its token had left.
	 *
	 * Much shorter than a token's fourteen days: the token has to survive being
	 * forwarded to a customer who reads their mail next week, but the browser
	 * session it opens does not. Bounded growth (hard rule 12) comes from this
	 * expiry — every entry this class writes deletes itself.
	 *
	 * @var int
	 */
	public const CONTEXT_TTL = HOUR_IN_SECONDS;

	/**
	 * Length of the opaque context id, in hex characters.
	 *
	 * @var int
	 */
	private const ID_LENGTH = 32;

	/**
	 * Memoised token for this request, so repeated filter calls cost one read.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param TokenService $tokens Verifies the token before a context is minted.
	 * @param Cache        $cache  Holds the id-to-token mapping.
	 * @param Logger       $logger Structured security events.
	 */
	public function __construct(
		private TokenService $tokens,
		private Cache $cache,
		private Logger $logger
	) {}

	/**
	 * Wires the exchange and the identity filter.
	 *
	 * The filter is registered in every context, including REST, because a
	 * guest who exchanged their token on a page view then acts on the order
	 * over REST and carries only the cookie. Priority 20 and the empty-value
	 * guard in `supply_token()` are what keep it from overwriting a token a
	 * REST controller passed as a parameter.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_exchange_token' ), 1 );
		add_filter( 'wpmphub_current_request_token', array( $this, 'supply_token' ), 20 );
	}

	/**
	 * Hook callback: exchanges the token, then leaves.
	 *
	 * Deliberately nothing but the exchange and the redirect, so the part with
	 * behaviour worth asserting is `exchange()` and the part that ends the
	 * request is too small to get wrong.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function maybe_exchange_token(): void {
		$target = $this->exchange();

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 302 );

		// The redirect target renders the order; continuing to render this
		// request as well would do the same work twice under a URL that still
		// contains the token.
		exit;
	}

	/**
	 * Swaps a token in the URL for a cookie-bound context.
	 *
	 * @since 0.11.0
	 *
	 * @return string|null Where to send the visitor, or null when this request carries no token.
	 */
	public function exchange(): ?string {
		$token = self::token_from_url();

		if ( '' === $token ) {
			return null;
		}

		// The landing request carries order-scoped credentials and, after the
		// redirect, order data. Neither may be written to a page cache.
		Sanitizer::nocache();

		$payload = $this->tokens->decode( $token );

		if ( null === $payload ) {
			$this->logger->warning(
				'Rejected a secure order link that did not verify.',
				array( 'event' => 'wpmphub.context.rejected' )
			);

			return self::target( self::STATE_EXPIRED );
		}

		$this->store( $token, $payload->expiry );

		return self::target( self::STATE_READY );
	}

	/**
	 * Supplies the token bound to this request, for OwnershipResolver.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $token Token another caller already supplied, if any.
	 * @return string
	 */
	public function supply_token( $token ): string {
		$supplied = is_string( $token ) ? $token : '';

		// A REST controller passing an explicit token wins: it is scoped to
		// that one request, while a cookie outlives it.
		if ( '' !== $supplied ) {
			return $supplied;
		}

		if ( null === $this->token ) {
			$this->token = $this->token_from_cookie();

			// Every request that resolves a guest identity is about to carry
			// order data, not just the landing request that minted the context
			// (docs/MILESTONE-PROMPTS.md M11 point 5). The cookie is in each
			// supported page cache's exclusion list as well, but that only
			// covers the caches this plugin knows the name of.
			if ( '' !== $this->token ) {
				Sanitizer::nocache();
			}
		}

		return $this->token;
	}

	/**
	 * Cookie attributes for a context, exposed so they can be asserted.
	 *
	 * `HttpOnly` because no script has any reason to read this; `Secure`
	 * wherever the request arrived over TLS; `SameSite=Lax` rather than
	 * `Strict` because the very first navigation is a top-level click from an
	 * email client, which `Strict` would strip — leaving the customer at their
	 * own order page with no context.
	 *
	 * @since 0.11.0
	 *
	 * @param int $expires Absolute expiry timestamp.
	 * @return array<string, mixed>
	 */
	public static function cookie_options( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	/**
	 * Mints a context id, stores the token against it and sets the cookie.
	 *
	 * @since 0.11.0
	 *
	 * @param string $token         Verified token.
	 * @param int    $token_expiry  The token's own expiry timestamp.
	 * @return void
	 */
	private function store( string $token, int $token_expiry ): void {
		$id = self::mint_id();

		if ( '' === $id ) {
			return;
		}

		// Never outlive the token the context stands for: a context that
		// survived its own token would be an access grant with no expiry path.
		$ttl = min( self::CONTEXT_TTL, max( 1, $token_expiry - time() ) );

		$this->cache->set( self::CACHE_PREFIX . hash( 'sha256', $id ), $token, $ttl );

		// Set before the redirect so a callback on this same request that asks
		// OwnershipResolver a question gets the identity the visitor just proved.
		$_COOKIE[ self::COOKIE ] = $id;
		$this->token             = $token;

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $id, self::cookie_options( time() + $ttl ) );
		}
	}

	/**
	 * This request's URL, token removed and outcome recorded.
	 *
	 * The state argument is what lets a browser that refused the cookie be told
	 * so, instead of landing on an order page that denies it for no visible
	 * reason. It names an outcome and nothing else — no order, no address, no
	 * fragment of the token.
	 *
	 * @since 0.11.0
	 *
	 * @param string $state Value for the state argument.
	 * @return string
	 */
	private static function target( string $state ): string {
		return add_query_arg( self::STATE_PARAM, $state, Urls::current( array( SecureLink::TOKEN_PARAM, self::STATE_PARAM ) ) );
	}

	/**
	 * The token in this request's query string, if it is even shaped like one.
	 *
	 * Read here rather than in `OwnershipResolver` because the resolver is
	 * deliberately transport-agnostic. Only the shape is checked; the signature
	 * is `TokenService`'s business.
	 *
	 * @since 0.11.0
	 * @return string
	 */
	private static function token_from_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A token arriving from an email link cannot carry a nonce; the token's own HMAC is the authentication, verified below by TokenService.
		$raw = isset( $_GET[ SecureLink::TOKEN_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ SecureLink::TOKEN_PARAM ] ) ) : '';

		return 1 === preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $raw ) ? $raw : '';
	}

	/**
	 * The token this request's context cookie stands for.
	 *
	 * @since 0.11.0
	 * @return string
	 */
	private function token_from_cookie(): string {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{' . self::ID_LENGTH . '}$/', $id ) ) {
			return '';
		}

		$token = $this->cache->get( self::CACHE_PREFIX . hash( 'sha256', $id ), '' );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * A fresh context id.
	 *
	 * @since 0.11.0
	 * @return string Empty when the platform could not produce randomness.
	 */
	private static function mint_id(): string {
		try {
			return bin2hex( random_bytes( self::ID_LENGTH / 2 ) );
		} catch ( \Exception $e ) {
			unset( $e );

			// No fallback: a predictable context id is a credential an attacker
			// can guess, and no context at all only costs the visitor a retry.
			return '';
		}
	}
}
