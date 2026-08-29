<?php
/**
 * Cache unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Support\Cache;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers both cache backends, because the rate limiter downstream must not
 * behave differently on a store that happens to run Redis.
 *
 * @since 0.1.0
 *
 * @covers \PostPurchaseHub\Support\Cache
 */
final class CacheTest extends TestCase {

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
	 * Values round-trip through the transient backend.
	 *
	 * @return void
	 */
	public function test_it_round_trips_a_value_without_an_object_cache(): void {
		$cache = new Cache();

		$this->assertTrue( $cache->set( 'stages', array( 'placed' ), 60 ) );
		$this->assertSame( array( 'placed' ), $cache->get( 'stages' ) );
		$this->assertSame( array( 'wpmphub_0_stages' ), FakeWordPress::transient_names() );
		$this->assertSame( array(), FakeWordPress::object_cache_keys( Cache::GROUP ) );

		$this->assertTrue( $cache->delete( 'stages' ) );
		$this->assertNull( $cache->get( 'stages' ) );
	}

	/**
	 * Values round-trip through the object cache backend.
	 *
	 * @return void
	 */
	public function test_it_round_trips_a_value_with_an_object_cache(): void {
		FakeWordPress::$ext_object_cache = true;

		$cache = new Cache();

		$this->assertTrue( $cache->set( 'stages', array( 'placed' ), 60 ) );
		$this->assertSame( array( 'placed' ), $cache->get( 'stages' ) );
		$this->assertSame( array( 'wpmphub_0_stages' ), FakeWordPress::object_cache_keys( Cache::GROUP ) );
		$this->assertSame( array(), FakeWordPress::transient_names() );

		$this->assertTrue( $cache->delete( 'stages' ) );
		$this->assertNull( $cache->get( 'stages' ) );
	}

	/**
	 * A miss returns the supplied default in both backends.
	 *
	 * @return void
	 */
	public function test_a_miss_returns_the_default(): void {
		$cache = new Cache();

		$this->assertSame( 'fallback', $cache->get( 'absent', 'fallback' ) );

		FakeWordPress::$ext_object_cache = true;

		$this->assertSame( 'fallback', $cache->get( 'absent', 'fallback' ) );
	}

	/**
	 * A zero or negative TTL is refused rather than cached forever.
	 *
	 * @return void
	 */
	public function test_it_refuses_to_store_without_an_expiry(): void {
		$cache = new Cache();

		$this->assertFalse( $cache->set( 'stages', 'value', 0 ) );
		$this->assertFalse( $cache->set( 'stages', 'value', -1 ) );
		$this->assertSame( array(), FakeWordPress::transient_names() );
	}

	/**
	 * Counters increment from zero without an object cache.
	 *
	 * @return void
	 */
	public function test_incr_counts_up_without_an_object_cache(): void {
		$cache = new Cache();

		$this->assertSame( 1, $cache->incr( 'ip:198.51.100.4', 900 ) );
		$this->assertSame( 2, $cache->incr( 'ip:198.51.100.4', 900 ) );
		$this->assertSame( 5, $cache->incr( 'ip:198.51.100.4', 900, 3 ) );
		$this->assertSame( 5, $cache->get( 'ip:198.51.100.4' ) );
	}

	/**
	 * Counters increment from zero with an object cache.
	 *
	 * @return void
	 */
	public function test_incr_counts_up_with_an_object_cache(): void {
		FakeWordPress::$ext_object_cache = true;

		$cache = new Cache();

		$this->assertSame( 1, $cache->incr( 'ip:198.51.100.4', 900 ) );
		$this->assertSame( 2, $cache->incr( 'ip:198.51.100.4', 900 ) );
		$this->assertSame( 5, $cache->incr( 'ip:198.51.100.4', 900, 3 ) );
		$this->assertSame( 5, $cache->get( 'ip:198.51.100.4' ) );
	}

	/**
	 * The transient backend keeps the window that the first increment opened.
	 *
	 * A sliding window would let a caller stay throttled forever, or escape a
	 * limit forever, depending on which way the expiry moved.
	 *
	 * @return void
	 */
	public function test_incr_does_not_extend_an_open_window_without_an_object_cache(): void {
		$cache = new Cache();

		$cache->incr( 'ip:198.51.100.4', 900 );

		// Pretend the window opened 893 seconds ago.
		FakeWordPress::$options['_transient_timeout_wpmphub_0_ip_198_51_100_4'] = time() + 7;

		$cache->incr( 'ip:198.51.100.4', 900 );

		$writes = FakeWordPress::$transient_writes['wpmphub_0_ip_198_51_100_4'];

		$this->assertSame( 900, $writes[0] );
		$this->assertLessThanOrEqual( 7, $writes[1] );
		$this->assertGreaterThan( 0, $writes[1] );
	}

	/**
	 * The object cache backend applies the TTL once, when the window opens.
	 *
	 * @return void
	 */
	public function test_incr_does_not_extend_an_open_window_with_an_object_cache(): void {
		FakeWordPress::$ext_object_cache = true;

		$cache = new Cache();

		$cache->incr( 'ip:198.51.100.4', 900 );
		$opened = FakeWordPress::$object_cache[ Cache::GROUP ]['wpmphub_0_ip_198_51_100_4']['expires'];

		$cache->incr( 'ip:198.51.100.4', 900 );

		$this->assertSame(
			$opened,
			FakeWordPress::$object_cache[ Cache::GROUP ]['wpmphub_0_ip_198_51_100_4']['expires']
		);
	}

	/**
	 * An entry evicted mid-window restarts the count instead of returning false.
	 *
	 * @return void
	 */
	public function test_incr_recovers_from_a_non_numeric_entry(): void {
		FakeWordPress::$ext_object_cache = true;

		$cache = new Cache();

		FakeWordPress::$object_cache[ Cache::GROUP ]['wpmphub_0_ip_198_51_100_4'] = array(
			'value'   => 'not-a-number',
			'expires' => time() + 900,
		);

		$this->assertSame( 1, $cache->incr( 'ip:198.51.100.4', 900 ) );
	}

	/**
	 * Flushing makes every previously written key unreadable, in both backends.
	 *
	 * @return void
	 */
	public function test_flush_invalidates_every_key(): void {
		foreach ( array( false, true ) as $object_cache ) {
			FakeWordPress::reset();
			FakeWordPress::$ext_object_cache = $object_cache;

			$cache = new Cache();
			$cache->set( 'stages', 'value', 60 );
			$cache->incr( 'ip:198.51.100.4', 900 );

			$cache->flush();

			$this->assertNull( $cache->get( 'stages' ) );
			$this->assertSame( 1, $cache->incr( 'ip:198.51.100.4', 900 ) );
			$this->assertSame( 1, get_option( Cache::GENERATION_OPTION ) );
		}
	}

	/**
	 * A fresh instance picks up a generation bumped by another request.
	 *
	 * @return void
	 */
	public function test_a_bumped_generation_is_honoured_by_a_new_instance(): void {
		$cache = new Cache();
		$cache->set( 'stages', 'value', 60 );

		( new Cache() )->flush();

		$this->assertNull( ( new Cache() )->get( 'stages' ) );
	}

	/**
	 * Keys are namespaced and stay inside the transient name budget.
	 *
	 * @return void
	 */
	public function test_long_keys_are_hashed_within_the_transient_name_budget(): void {
		$cache = new Cache();

		$cache->set( str_repeat( 'order-lookup-attempt-', 20 ), 'value', 60 );

		$names = FakeWordPress::transient_names();

		$this->assertCount( 1, $names );
		$this->assertStringStartsWith( 'wpmphub_0_', $names[0] );
		$this->assertLessThanOrEqual( 172, strlen( $names[0] ) );
	}

	/**
	 * Two different long keys do not collide after hashing.
	 *
	 * @return void
	 */
	public function test_hashed_keys_stay_distinct(): void {
		$cache = new Cache();

		$cache->set( str_repeat( 'a', 200 ) . '-one', 'first', 60 );
		$cache->set( str_repeat( 'a', 200 ) . '-two', 'second', 60 );

		$this->assertCount( 2, FakeWordPress::transient_names() );
	}
}
