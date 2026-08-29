<?php
/**
 * SecureLink unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the one contract that matters before M11's guest landing page
 * exists: the link decodes back to the right order and expires on schedule.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\SecureLink
 */
final class SecureLinkTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state and installs a token secret.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options['wpmphub_token_secret'] = base64_encode( random_bytes( 64 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture, not obfuscation.
	}

	/**
	 * The link carries a token that decodes to the order's own id and key.
	 *
	 * @return void
	 */
	public function test_link_carries_a_token_that_decodes_to_the_order(): void {
		$order = new \WC_Order( 501 );
		$order->set_order_key( 'wc_order_abc123' );

		$url = SecureLink::url( $order, new TokenService() );

		$this->assertStringContainsString( 'wpmphub_token=', $url );

		$token   = $this->token_from_url( $url );
		$payload = ( new TokenService() )->decode( $token );

		$this->assertNotNull( $payload );
		$this->assertSame( 501, $payload->order_id );
		$this->assertSame( 'wc_order_abc123', $payload->order_key );
	}

	/**
	 * A rotated order key invalidates the link — the same guarantee
	 * `OwnershipResolver` relies on, exercised here from the issuing side.
	 *
	 * @return void
	 */
	public function test_link_stops_matching_after_the_order_key_rotates(): void {
		$order = new \WC_Order( 501 );
		$order->set_order_key( 'wc_order_original' );

		$url   = SecureLink::url( $order, new TokenService() );
		$token = $this->token_from_url( $url );

		$order->set_order_key( 'wc_order_rotated' );

		$payload = ( new TokenService() )->decode( $token );

		$this->assertNotNull( $payload, 'The token itself is still well-formed and unexpired.' );
		$this->assertNotSame(
			$order->get_order_key(),
			$payload->order_key,
			'OwnershipResolver compares against the *current* key, which no longer matches.'
		);
	}

	/**
	 * An explicit TTL is honoured: a link issued to expire immediately
	 * decodes to nothing once its second has passed.
	 *
	 * @return void
	 */
	public function test_an_explicit_ttl_expires_the_link(): void {
		$order = new \WC_Order( 501 );
		$order->set_order_key( 'wc_order_abc123' );

		$url   = SecureLink::url( $order, new TokenService(), -1 );
		$token = $this->token_from_url( $url );

		$this->assertNull( ( new TokenService() )->decode( $token ) );
	}

	/**
	 * Extracts the wpmphub_token query argument from a URL built by add_query_arg().
	 *
	 * @param string $url URL to parse.
	 * @return string
	 */
	private function token_from_url( string $url ): string {
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		parse_str( (string) $query, $args );

		return (string) ( $args[ SecureLink::TOKEN_PARAM ] ?? '' );
	}
}
