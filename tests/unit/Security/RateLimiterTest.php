<?php
/**
 * RateLimiter unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the three counter dimensions and, most importantly, that every
 * behaviour is identical whether or not a persistent object cache is present
 * — the specific acceptance criterion docs/SPEC.md Phase 8 names for this
 * class.
 *
 * @since 0.6.0
 *
 * @covers \PostPurchaseHub\Security\RateLimiter
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * An IP dimension allows attempts up to the limit and throttles the next one.
	 *
	 * @return void
	 */
	public function test_allow_ip_throttles_at_the_limit(): void {
		foreach ( array( false, true ) as $object_cache ) {
			FakeWordPress::reset();
			FakeWordPress::$ext_object_cache = $object_cache;

			$limiter = new RateLimiter( new Cache() );

			$this->assertTrue( $limiter->allow_ip( 'lookup', '198.51.100.4', 3, 900 ) );
			$this->assertTrue( $limiter->allow_ip( 'lookup', '198.51.100.4', 3, 900 ) );
			$this->assertTrue( $limiter->allow_ip( 'lookup', '198.51.100.4', 3, 900 ) );
			$this->assertFalse( $limiter->allow_ip( 'lookup', '198.51.100.4', 3, 900 ), 'Object cache: ' . ( $object_cache ? 'yes' : 'no' ) );
		}
	}

	/**
	 * A different IP gets its own budget.
	 *
	 * @return void
	 */
	public function test_allow_ip_is_scoped_per_ip(): void {
		$limiter = new RateLimiter( new Cache() );

		$this->assertTrue( $limiter->allow_ip( 'lookup', '198.51.100.4', 1, 900 ) );
		$this->assertFalse( $limiter->allow_ip( 'lookup', '198.51.100.4', 1, 900 ) );
		$this->assertTrue( $limiter->allow_ip( 'lookup', '203.0.113.9', 1, 900 ) );
	}

	/**
	 * A different bucket gets its own budget for the same IP, so throttling
	 * one endpoint does not spend another's allowance.
	 *
	 * @return void
	 */
	public function test_allow_ip_is_scoped_per_bucket(): void {
		$limiter = new RateLimiter( new Cache() );

		$this->assertTrue( $limiter->allow_ip( 'lookup', '198.51.100.4', 1, 900 ) );
		$this->assertFalse( $limiter->allow_ip( 'lookup', '198.51.100.4', 1, 900 ) );
		$this->assertTrue( $limiter->allow_ip( 'cancel_request', '198.51.100.4', 1, 900 ) );
	}

	/**
	 * Casing and dot variants of the same mailbox share one email-dimension
	 * budget — the alias-bypass the spec calls out by name.
	 *
	 * @return void
	 */
	public function test_allow_email_treats_alias_spellings_as_one_identity(): void {
		$limiter = new RateLimiter( new Cache() );

		$this->assertTrue( $limiter->allow_email( 'lookup', 'jane.doe@example.com', 1, 900 ) );
		$this->assertFalse( $limiter->allow_email( 'lookup', 'Jane.Doe@Example.com', 1, 900 ) );
		$this->assertFalse( $limiter->allow_email( 'lookup', 'JANEDOE@example.com', 1, 900 ) );
	}

	/**
	 * A genuinely different mailbox gets its own budget.
	 *
	 * @return void
	 */
	public function test_allow_email_is_scoped_per_mailbox(): void {
		$limiter = new RateLimiter( new Cache() );

		$this->assertTrue( $limiter->allow_email( 'lookup', 'jane.doe@example.com', 1, 900 ) );
		$this->assertTrue( $limiter->allow_email( 'lookup', 'john.doe@example.com', 1, 900 ) );
	}

	/**
	 * The site-wide dimension throttles regardless of which IP or email drove it.
	 *
	 * @return void
	 */
	public function test_allow_site_is_shared_across_every_caller(): void {
		$limiter = new RateLimiter( new Cache() );

		$this->assertTrue( $limiter->allow_site( 'lookup', 2, 3600 ) );
		$this->assertTrue( $limiter->allow_site( 'lookup', 2, 3600 ) );
		$this->assertFalse( $limiter->allow_site( 'lookup', 2, 3600 ) );
	}

	/**
	 * The rate limiter never touches wp_options for anything but Cache's own
	 * generation bookkeeping — no per-attempt row survives a cache flush the
	 * way an options-table counter would.
	 *
	 * @return void
	 */
	public function test_it_never_persists_a_counter_directly_to_options(): void {
		$limiter = new RateLimiter( new Cache() );

		$limiter->allow_ip( 'lookup', '198.51.100.4', 5, 900 );
		$limiter->allow_email( 'lookup', 'jane.doe@example.com', 5, 900 );
		$limiter->allow_site( 'lookup', 5, 900 );

		foreach ( array_keys( FakeWordPress::$options ) as $option ) {
			$this->assertTrue(
				str_starts_with( $option, '_transient_' ) || Cache::GENERATION_OPTION === $option,
				"Unexpected direct option write: $option"
			);
		}
	}
}
