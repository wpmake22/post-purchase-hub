<?php
/**
 * GuestContext unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Frontend\GuestContext;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers docs/MILESTONE-PROMPTS.md M11 point 4 and its acceptance criterion
 * "token is absent from the URL after the first navigation", plus the replay,
 * tamper and expiry cases the milestone names.
 *
 * Replay is deliberately *allowed* within the TTL and the test says so: a token
 * burned on first GET is a token a corporate mail scanner destroys before the
 * customer ever clicks it (docs/SPEC.md Phase 8, risk T9). What must not
 * survive is a tampered or expired one.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Frontend\GuestContext
 */
final class GuestContextTest extends TestCase {

	/**
	 * Context under test.
	 *
	 * @var GuestContext
	 */
	private GuestContext $context;

	/**
	 * Token issuer, shared with the context.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';

		$this->tokens  = new TokenService();
		$this->context = new GuestContext( $this->tokens, new Cache(), new Logger() );

		$_GET    = array();
		$_COOKIE = array();

		$_SERVER['REQUEST_URI'] = '/my-account/view-order/42/';
	}

	/**
	 * Clears the superglobals this class reads.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET    = array();
		$_COOKIE = array();

		unset( $_SERVER['REQUEST_URI'] );

		parent::tearDown();
	}

	/**
	 * Puts a token on the request, the way an emailed link does.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function land_with( string $token ): void {
		$_GET[ SecureLink::TOKEN_PARAM ] = $token;
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/42/?' . SecureLink::TOKEN_PARAM . '=' . rawurlencode( $token );
	}

	/**
	 * A valid token is exchanged, and the visitor is sent somewhere that does
	 * not contain it.
	 *
	 * @return void
	 */
	public function test_the_token_is_absent_from_the_redirect_target(): void {
		$token = $this->tokens->issue( 42, 'wc_order_key_42' );

		$this->land_with( $token );

		$target = $this->context->exchange();

		$this->assertIsString( $target );
		$this->assertStringNotContainsString( SecureLink::TOKEN_PARAM, (string) $target );
		$this->assertStringNotContainsString( substr( $token, 0, 12 ), (string) $target );
		$this->assertStringContainsString( '/my-account/view-order/42/', (string) $target );
		$this->assertStringContainsString( GuestContext::STATE_PARAM . '=' . GuestContext::STATE_READY, (string) $target );
	}

	/**
	 * The redirect stays on this site, built from the site's own host rather
	 * than whatever the request claimed.
	 *
	 * @return void
	 */
	public function test_the_redirect_target_stays_on_this_site(): void {
		$this->land_with( $this->tokens->issue( 42, 'wc_order_key_42' ) );

		// home_url()'s host, not the request's: a spoofed Host header cannot
		// steer where the visitor is sent next.
		$this->assertStringStartsWith( rtrim( (string) home_url(), '/' ) . '/', (string) $this->context->exchange() );
	}

	/**
	 * After the exchange the token is reachable through the filter
	 * OwnershipResolver reads, keyed only by the cookie.
	 *
	 * @return void
	 */
	public function test_the_exchanged_token_is_served_from_the_cookie(): void {
		$token = $this->tokens->issue( 42, 'wc_order_key_42' );

		$this->land_with( $token );
		$this->context->exchange();

		$id = self::context_cookie();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', (string) $id );

		// A second request: the URL no longer carries the token, only the cookie.
		$_GET   = array();
		$fresh  = new GuestContext( $this->tokens, new Cache(), new Logger() );
		$served = $fresh->supply_token( '' );

		$this->assertSame( $token, $served );
	}

	/**
	 * Replaying the same link inside its TTL keeps working. Mail scanners
	 * pre-fetch these URLs; burning one would hand the customer a dead link.
	 *
	 * @return void
	 */
	public function test_a_replayed_link_still_works_within_the_ttl(): void {
		$token = $this->tokens->issue( 42, 'wc_order_key_42' );

		$this->land_with( $token );

		$first    = $this->context->exchange();
		$first_id = self::context_cookie();

		// A different browser — the customer's, after the scanner's fetch.
		$_COOKIE = array();

		$this->land_with( $token );

		$second    = $this->context->exchange();
		$second_id = self::context_cookie();

		$this->assertSame( $first, $second, 'The same link must land in the same place every time.' );
		$this->assertNotSame( $first_id, $second_id, 'Each exchange must mint its own context, not share one.' );
		$this->assertSame( $token, ( new GuestContext( $this->tokens, new Cache(), new Logger() ) )->supply_token( '' ) );
	}

	/**
	 * A token whose signature was altered mints no context.
	 *
	 * @return void
	 */
	public function test_a_tampered_token_mints_no_context(): void {
		$token = $this->tokens->issue( 42, 'wc_order_key_42' );

		[ $payload, $signature ] = explode( '.', $token );

		// One flipped hex digit in the signature.
		$tampered = $payload . '.' . substr_replace( $signature, '0' === $signature[0] ? '1' : '0', 0, 1 );

		$this->land_with( $tampered );

		$target = $this->context->exchange();

		$this->assertStringContainsString( GuestContext::STATE_PARAM . '=' . GuestContext::STATE_EXPIRED, (string) $target );
		$this->assertArrayNotHasKey( GuestContext::COOKIE, $_COOKIE );
		$this->assertSame( '', $this->context->supply_token( '' ) );
	}

	/**
	 * So does one whose payload was altered to name a different order.
	 *
	 * @return void
	 */
	public function test_a_token_repointed_at_another_order_mints_no_context(): void {
		$token = $this->tokens->issue( 42, 'wc_order_key_42' );

		[ , $signature ] = explode( '.', $token );

		$forged = rtrim( strtr( base64_encode( '43|wc_order_key_42|' . ( time() + 3600 ) ), '+/', '-_' ), '=' ) . '.' . $signature; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Forging a token payload to prove it is rejected.

		$this->land_with( $forged );

		$this->assertStringContainsString( GuestContext::STATE_PARAM . '=' . GuestContext::STATE_EXPIRED, (string) $this->context->exchange() );
		$this->assertArrayNotHasKey( GuestContext::COOKIE, $_COOKIE );
	}

	/**
	 * An expired token mints no context.
	 *
	 * @return void
	 */
	public function test_an_expired_token_mints_no_context(): void {
		$expired = $this->tokens->issue( 42, 'wc_order_key_42', -60 );

		$this->land_with( $expired );

		$this->assertStringContainsString( GuestContext::STATE_PARAM . '=' . GuestContext::STATE_EXPIRED, (string) $this->context->exchange() );
		$this->assertArrayNotHasKey( GuestContext::COOKIE, $_COOKIE );
	}

	/**
	 * A rejected link is logged as a security event.
	 *
	 * @return void
	 */
	public function test_a_rejected_link_is_logged(): void {
		$this->land_with( $this->tokens->issue( 42, 'wc_order_key_42', -60 ) );
		$this->context->exchange();

		$rejections = array_filter(
			FakeWordPress::$logged,
			static function ( array $line ): bool {
				return 'pph.context.rejected' === ( $line['context']['event'] ?? '' );
			}
		);

		$this->assertNotEmpty( $rejections );
	}

	/**
	 * A request with no token is left entirely alone: no redirect, no cookie.
	 *
	 * @return void
	 */
	public function test_a_request_without_a_token_is_untouched(): void {
		$this->assertNull( $this->context->exchange() );
		$this->assertArrayNotHasKey( GuestContext::COOKIE, $_COOKIE );
	}

	/**
	 * Something in the query argument that is not shaped like a token is not
	 * even handed to the verifier.
	 *
	 * @return void
	 */
	public function test_a_malformed_token_argument_is_ignored(): void {
		$this->land_with( 'not-a-token' );

		$this->assertNull( $this->context->exchange() );
	}

	/**
	 * A context never outlives the token it stands for.
	 *
	 * @return void
	 */
	public function test_a_context_expires_no_later_than_its_token(): void {
		$this->land_with( $this->tokens->issue( 42, 'wc_order_key_42', 30 ) );
		$this->context->exchange();

		$ttls = array();

		foreach ( FakeWordPress::$transient_writes as $written ) {
			foreach ( $written as $ttl ) {
				$ttls[] = $ttl;
			}
		}

		$this->assertNotEmpty( $ttls );
		$this->assertLessThanOrEqual( 30, max( $ttls ) );
	}

	/**
	 * A cookie is HttpOnly, SameSite=Lax and Secure over TLS, so no script can
	 * read it and the first click from an email client still carries it.
	 *
	 * @return void
	 */
	public function test_the_cookie_is_http_only_and_lax(): void {
		$options = GuestContext::cookie_options( time() + 60 );

		$this->assertTrue( $options['httponly'] );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertFalse( $options['secure'] );

		FakeWordPress::$is_ssl = true;

		$this->assertTrue( GuestContext::cookie_options( time() + 60 )['secure'] );
	}

	/**
	 * A token supplied explicitly by a REST controller wins over the cookie:
	 * the parameter is scoped to one request, the cookie outlives it.
	 *
	 * @return void
	 */
	public function test_an_explicit_token_is_not_overridden_by_the_cookie(): void {
		$this->land_with( $this->tokens->issue( 42, 'wc_order_key_42' ) );
		$this->context->exchange();

		$this->assertSame( 'explicit-token', $this->context->supply_token( 'explicit-token' ) );
	}

	/**
	 * A request that resolves a guest identity from the cookie is marked
	 * uncacheable, not only the landing request that minted the context.
	 *
	 * @return void
	 */
	public function test_a_cookie_borne_request_is_marked_uncacheable(): void {
		$this->land_with( $this->tokens->issue( 42, 'wc_order_key_42' ) );
		$this->context->exchange();

		$_GET = array();

		$fresh = new GuestContext( $this->tokens, new Cache(), new Logger() );

		$this->assertNotSame( '', $fresh->supply_token( '' ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * A forged cookie value naming no stored context yields no token.
	 *
	 * @return void
	 */
	public function test_a_forged_cookie_yields_no_token(): void {
		$_COOKIE[ GuestContext::COOKIE ] = str_repeat( 'a', 32 );

		$this->assertSame( '', $this->context->supply_token( '' ) );
	}

	/**
	 * The context id the exchange put in this request's cookie jar.
	 *
	 * Read through a helper so the one place a superglobal is touched carries
	 * the one annotation explaining why: this is the test asserting what the
	 * class under test wrote, not code handling a real visitor's input.
	 *
	 * @return string
	 */
	private static function context_cookie(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Reading back what the class under test wrote into the test's own cookie jar.
		return isset( $_COOKIE[ GuestContext::COOKIE ] ) ? (string) $_COOKIE[ GuestContext::COOKIE ] : '';
	}

	/**
	 * The filter and the landing hook are both registered.
	 *
	 * @return void
	 */
	public function test_it_registers_the_exchange_and_the_identity_filter(): void {
		$this->context->register();

		$this->assertArrayHasKey( 'template_redirect', FakeWordPress::$actions );
		$this->assertArrayHasKey( 'pph_current_request_token', FakeWordPress::$filters );
	}
}
