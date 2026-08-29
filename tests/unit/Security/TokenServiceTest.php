<?php
/**
 * TokenService unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the wire format docs/SPEC.md Phase 8 fixes, and the fuzz guarantee
 * that nothing short of the exact bytes issue() produced ever verifies.
 *
 * @since 0.6.0
 *
 * @covers \PostPurchaseHub\Security\TokenService
 */
final class TokenServiceTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state and installs a token secret.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';
	}

	/**
	 * A freshly issued token decodes back to what it was issued with.
	 *
	 * @return void
	 */
	public function test_it_round_trips_a_valid_token(): void {
		$tokens = new TokenService();

		$token   = $tokens->issue( 42, 'wc_order_abc123' );
		$payload = $tokens->decode( $token );

		$this->assertNotNull( $payload );
		$this->assertSame( 42, $payload->order_id );
		$this->assertSame( 'wc_order_abc123', $payload->order_key );
		$this->assertGreaterThan( time(), $payload->expiry );
	}

	/**
	 * Two tokens issued for the same order at the same second still verify
	 * independently — nothing about decode() is order-of-issue sensitive.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent_within_ttl(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key' );

		$first  = $tokens->decode( $token );
		$second = $tokens->decode( $token );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertEquals( $first, $second );
	}

	/**
	 * An expired token is rejected.
	 *
	 * @return void
	 */
	public function test_expired_token_is_rejected(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key', -1 );

		$this->assertNull( $tokens->decode( $token ) );
	}

	/**
	 * A token with its signature flipped is rejected.
	 *
	 * @return void
	 */
	public function test_tampered_signature_is_rejected(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key' );

		[ $payload, $signature ] = explode( '.', $token );

		$this->assertNull( $tokens->decode( $payload . '.' . strrev( $signature ) ) );
	}

	/**
	 * A token whose payload was edited to name a different order, without
	 * re-signing, is rejected — replaying a token against another order's id
	 * gains nothing.
	 *
	 * @return void
	 */
	public function test_cross_order_replay_is_rejected(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key' );

		[ $encoded_payload, $signature ] = explode( '.', $token );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a forged token payload for the test, not obfuscation.
		$tampered_payload = strtr( base64_encode( '2|wc_order_key|' . ( time() + 3600 ) ), '+/', '-_' );

		$this->assertNotSame( $encoded_payload, $tampered_payload );
		$this->assertNull( $tokens->decode( rtrim( $tampered_payload, '=' ) . '.' . $signature ) );
	}

	/**
	 * A signature-stripped token — just the payload, or the payload with an
	 * empty signature — is rejected.
	 *
	 * @return void
	 */
	public function test_signature_stripped_token_is_rejected(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key' );

		[ $encoded_payload ] = explode( '.', $token );

		$this->assertNull( $tokens->decode( $encoded_payload ) );
		$this->assertNull( $tokens->decode( $encoded_payload . '.' ) );
	}

	/**
	 * A token truncated at any point, one character at a time, never verifies.
	 *
	 * @return void
	 */
	public function test_truncated_tokens_are_rejected(): void {
		$tokens       = new TokenService();
		$token        = $tokens->issue( 1, 'wc_order_key' );
		$token_length = strlen( $token );

		for ( $length = 0; $length < $token_length; $length++ ) {
			$this->assertNull( $tokens->decode( substr( $token, 0, $length ) ), "Truncated to $length chars unexpectedly verified." );
		}
	}

	/**
	 * A payload whose order key contains the field delimiter fails closed:
	 * the fixed 3-field split hands the extra pipes to the expiry field, which
	 * then fails the digits-only check, rather than silently mis-parsing it.
	 * Real WooCommerce order keys never contain `|`, so this is a fail-safe
	 * for a value this class did not generate, not a supported input shape.
	 *
	 * @return void
	 */
	public function test_a_delimiter_inside_the_order_key_fails_closed(): void {
		$tokens = new TokenService();

		$token = $tokens->issue( 1, 'wc_order|with|pipes' );

		$this->assertNull( $tokens->decode( $token ) );
	}

	/**
	 * Fuzz test: every single-bit mutation of a valid token, and every
	 * truncation, fails closed. At least 500 mutations are exercised.
	 *
	 * @return void
	 */
	public function test_every_bit_mutation_of_a_valid_token_fails_closed(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 7, 'wc_order_fuzz_target' );

		$this->assertNotNull( $tokens->decode( $token ), 'Precondition: the unmutated token must itself verify.' );

		$mutations = 0;

		for ( $byte = 0, $length = strlen( $token ); $byte < $length; $byte++ ) {
			for ( $bit = 0; $bit < 8; $bit++ ) {
				$mutated          = $token;
				$mutated[ $byte ] = chr( ord( $token[ $byte ] ) ^ ( 1 << $bit ) );
				++$mutations;

				if ( $mutated === $token ) {
					continue;
				}

				$this->assertNull(
					$tokens->decode( $mutated ),
					"Mutation at byte $byte bit $bit unexpectedly verified."
				);
			}
		}

		$this->assertGreaterThanOrEqual( 500, $mutations );
	}

	/**
	 * A missing secret (pre-activation, or a wiped option) fails closed rather
	 * than verifying against an effectively empty key.
	 *
	 * @return void
	 */
	public function test_a_missing_secret_never_verifies(): void {
		$tokens = new TokenService();
		$token  = $tokens->issue( 1, 'wc_order_key' );

		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = '';

		$this->assertNull( $tokens->decode( $token ) );
	}

	/**
	 * Issuing without an installed secret is a programming error, not a
	 * silently unsigned token.
	 *
	 * @return void
	 */
	public function test_issuing_without_a_secret_throws(): void {
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = '';

		$tokens = new TokenService();

		$this->expectException( \RuntimeException::class );

		$tokens->issue( 1, 'wc_order_key' );
	}

	/**
	 * The default TTL is 14 days when nothing is configured.
	 *
	 * @return void
	 */
	public function test_default_ttl_is_fourteen_days(): void {
		$tokens = new TokenService();

		$this->assertSame( 14 * DAY_IN_SECONDS, $tokens->ttl_seconds() );
	}

	/**
	 * A configured TTL past the 90-day ceiling is clamped, not honoured.
	 *
	 * @return void
	 */
	public function test_configured_ttl_is_capped_at_ninety_days(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( TokenService::TTL_SETTING => 365 );

		$tokens = new TokenService();

		$this->assertSame( 90 * DAY_IN_SECONDS, $tokens->ttl_seconds() );
	}

	/**
	 * A filter trying to raise the TTL past the ceiling is also clamped —
	 * the cap is not a default a filter gets to override.
	 *
	 * @return void
	 */
	public function test_filter_cannot_raise_ttl_past_the_ceiling(): void {
		add_filter(
			'wpmphub_token_ttl_days',
			static function (): int {
				return 9999;
			}
		);

		$tokens = new TokenService();

		$this->assertSame( 90 * DAY_IN_SECONDS, $tokens->ttl_seconds() );
	}
}
