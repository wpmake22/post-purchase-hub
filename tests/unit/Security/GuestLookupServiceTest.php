<?php
/**
 * GuestLookupService unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\LookupResult;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the security properties docs/MILESTONE-PROMPTS.md M11 names: no
 * existence oracle, link only ever to the address on the order, rate limits on
 * all three dimensions with no alias bypass, structured logging on a throttle,
 * and a timing floor that both the cheap and the expensive path are pushed to.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Security\GuestLookupService
 */
final class GuestLookupServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var GuestLookupService
	 */
	private GuestLookupService $service;

	/**
	 * Builds the service over the real security collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options['wpmphub_settings'] = array(
			GuestAccess::ENABLED_SETTING      => true,
			GuestAccess::ACKNOWLEDGED_SETTING => true,
		);

		$this->service = new GuestLookupService(
			new GuestAccess(),
			new OrderLookup(),
			new RateLimiter( new Cache() ),
			new Logger()
		);

		// A floor short enough to run fifty times, long enough to measure.
		add_filter(
			'wpmphub_lookup_time_floor_ms',
			static function (): int {
				return 5;
			}
		);
	}

	/**
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int    $id    Order id.
	 * @param string $email Billing email.
	 * @return \WC_Order
	 */
	private function order( int $id, string $email = 'jane@example.com' ): \WC_Order {
		$order = new \WC_Order( $id, 'processing' );
		$order->set_billing_email( $email );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Collects the orders the deferred send would have mailed a link for.
	 *
	 * The send is registered on `shutdown` and fires there in production; here
	 * the recorded callbacks are invoked directly, which is also the only way to
	 * assert that nothing was queued at all.
	 *
	 * @return array<int, \WC_Order>
	 */
	private function mailed(): array {
		$mailed = array();

		add_action(
			'wpmphub_secure_link_requested',
			static function ( $order ) use ( &$mailed ): void {
				$mailed[] = $order;
			}
		);

		foreach ( FakeWordPress::$actions['shutdown'] ?? array() as $hooked ) {
			if ( is_callable( $hooked['callback'] ) ) {
				( $hooked['callback'] )();
			}
		}

		return $mailed;
	}

	/**
	 * A matching pair queues a link for the order's own address.
	 *
	 * @return void
	 */
	public function test_a_matching_pair_queues_a_link_for_the_order(): void {
		$order = $this->order( 42 );

		$result = $this->service->attempt( '42', 'jane@example.com', '203.0.113.9' );

		$this->assertTrue( $result->accepted() );
		$this->assertSame( array( $order ), $this->mailed() );
	}

	/**
	 * A wrong email queues nothing, so lookup cannot be turned into a way of
	 * mailing links about somebody else's order.
	 *
	 * @return void
	 */
	public function test_a_wrong_email_queues_nothing(): void {
		$this->order( 42, 'jane@example.com' );

		$result = $this->service->attempt( '42', 'attacker@example.com', '203.0.113.9' );

		$this->assertTrue( $result->accepted() );
		$this->assertSame( array(), $this->mailed() );
	}

	/**
	 * An order number nobody holds queues nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_order_queues_nothing(): void {
		$result = $this->service->attempt( '999', 'jane@example.com', '203.0.113.9' );

		$this->assertTrue( $result->accepted() );
		$this->assertSame( array(), $this->mailed() );
	}

	/**
	 * The three outcomes above are indistinguishable in what the visitor is
	 * told — the whole point of the endpoint.
	 *
	 * @return void
	 */
	public function test_every_outcome_returns_the_identical_message(): void {
		$this->order( 42, 'jane@example.com' );

		$hit    = $this->service->attempt( '42', 'jane@example.com', '203.0.113.1' );
		$wrong  = $this->service->attempt( '42', 'attacker@example.com', '203.0.113.2' );
		$absent = $this->service->attempt( '999', 'jane@example.com', '203.0.113.3' );

		$this->assertSame( $hit->status, $wrong->status );
		$this->assertSame( $hit->status, $absent->status );
		$this->assertSame( $hit->message, $wrong->message );
		$this->assertSame( $hit->message, $absent->message );
	}

	/**
	 * The per-IP limit fires at the configured threshold.
	 *
	 * @return void
	 */
	public function test_the_ip_limit_fires_at_its_threshold(): void {
		for ( $attempt = 0; $attempt < GuestLookupService::IP_LIMIT; $attempt++ ) {
			$this->assertTrue(
				$this->service->attempt( '999', 'jane' . $attempt . '@example.com', '203.0.113.9' )->accepted(),
				'Attempt ' . ( $attempt + 1 ) . ' is inside the limit and must be accepted.'
			);
		}

		$this->assertSame(
			LookupResult::THROTTLED,
			$this->service->attempt( '999', 'jane99@example.com', '203.0.113.9' )->status
		);
	}

	/**
	 * The per-address limit fires even as the attacker rotates IP addresses.
	 *
	 * @return void
	 */
	public function test_the_email_limit_fires_across_changing_ips(): void {
		for ( $attempt = 0; $attempt < GuestLookupService::EMAIL_LIMIT; $attempt++ ) {
			$this->service->attempt( '999', 'jane@example.com', '203.0.113.' . $attempt );
		}

		$this->assertSame(
			LookupResult::THROTTLED,
			$this->service->attempt( '999', 'jane@example.com', '198.51.100.7' )->status
		);
	}

	/**
	 * Alias spellings share one budget, so `+1`, `+2` and dotted variants are
	 * not a way around the per-address limit.
	 *
	 * @return void
	 */
	public function test_email_aliases_do_not_get_a_fresh_budget(): void {
		for ( $attempt = 0; $attempt < GuestLookupService::EMAIL_LIMIT; $attempt++ ) {
			$this->service->attempt( '999', 'ja.ne+' . $attempt . '@example.com', '203.0.113.' . $attempt );
		}

		$this->assertSame(
			LookupResult::THROTTLED,
			$this->service->attempt( '999', 'JANE@example.com', '198.51.100.7' )->status,
			'Every alias of one mailbox must share a single counter.'
		);
	}

	/**
	 * The site-wide limit is the backstop against one attempt per identity
	 * spread across a botnet.
	 *
	 * @return void
	 */
	public function test_the_site_limit_fires_across_changing_identities(): void {
		for ( $attempt = 0; $attempt < GuestLookupService::SITE_LIMIT; $attempt++ ) {
			$this->service->attempt( '999', 'jane' . $attempt . '@example.com', '203.0.113.' . ( $attempt % 200 ) );
		}

		$this->assertSame(
			LookupResult::THROTTLED,
			$this->service->attempt( '999', 'someone-new@example.com', '198.51.100.7' )->status
		);
	}

	/**
	 * A throttle writes a structured security event, and writes neither the
	 * address nor the address's full hash into it.
	 *
	 * @return void
	 */
	public function test_a_throttle_logs_a_structured_security_event(): void {
		for ( $attempt = 0; $attempt <= GuestLookupService::IP_LIMIT; $attempt++ ) {
			$this->service->attempt( '999', 'jane@example.com', '203.0.113.9' );
		}

		$events = array_values(
			array_filter(
				FakeWordPress::$logged,
				static function ( array $line ): bool {
					return 'wpmphub.lookup.throttled' === ( $line['context']['event'] ?? '' );
				}
			)
		);

		$this->assertNotEmpty( $events, 'A throttle must be logged.' );
		$this->assertSame( 'warning', $events[0]['level'] );
		$this->assertSame( 'ip', $events[0]['context']['stage'] );
		$this->assertArrayHasKey( 'email_hash', $events[0]['context'] );
		$this->assertArrayNotHasKey( 'email', $events[0]['context'] );

		$serialised = wp_json_encode( $events[0] );

		$this->assertIsString( $serialised );
		$this->assertStringNotContainsString( 'jane@example.com', $serialised );
		$this->assertStringNotContainsString( '203.0.113.9', $serialised );
	}

	/**
	 * A challenge plugin can reject an attempt, and the order is never touched
	 * when it does.
	 *
	 * @return void
	 */
	public function test_a_challenge_rejection_stops_the_attempt(): void {
		$this->order( 42 );

		add_filter(
			'wpmphub_lookup_challenge',
			static function () {
				return new \WP_Error( 'captcha_failed', 'Please complete the challenge.' );
			}
		);

		$result = $this->service->attempt( '42', 'jane@example.com', '203.0.113.9' );

		$this->assertSame( LookupResult::CHALLENGED, $result->status );
		$this->assertSame( 'Please complete the challenge.', $result->message );
		$this->assertSame( array(), $this->mailed() );
	}

	/**
	 * The challenge filter is handed a hash, never the submitted address.
	 *
	 * @return void
	 */
	public function test_the_challenge_filter_never_receives_the_address(): void {
		$seen = array();

		add_filter(
			'wpmphub_lookup_challenge',
			static function ( $rejection, $attempt ) use ( &$seen ) {
				$seen = $attempt;

				return $rejection;
			}
		);

		$this->service->attempt( '999', 'jane@example.com', '203.0.113.9' );

		$this->assertArrayHasKey( 'email_hash', $seen );
		$this->assertArrayNotHasKey( 'email', $seen );
		$this->assertNotSame( 'jane@example.com', $seen['email_hash'] );
	}

	/**
	 * A store that has not enabled lookup processes nothing, does not spend a
	 * rate-limit slot, and queues no mail.
	 *
	 * @return void
	 */
	public function test_a_disabled_store_processes_nothing(): void {
		FakeWordPress::$options['wpmphub_settings'] = array();

		$this->order( 42 );

		$result = $this->service->attempt( '42', 'jane@example.com', '203.0.113.9' );

		$this->assertSame( LookupResult::DISABLED, $result->status );
		$this->assertSame( array(), $this->mailed() );
	}

	/**
	 * Both the cheap path and the expensive one are held to the floor, which is
	 * what makes their durations equivalent.
	 *
	 * @return void
	 */
	public function test_both_a_hit_and_a_miss_are_held_to_the_timing_floor(): void {
		$this->order( 42 );

		$floor_ns = 5 * 1000000;

		$hit  = self::duration( fn() => $this->service->attempt( '42', 'jane@example.com', '203.0.113.1' ) );
		$miss = self::duration( fn() => $this->service->attempt( '999', 'nobody@example.com', '203.0.113.2' ) );

		$this->assertGreaterThanOrEqual( $floor_ns, $hit, 'A match must not answer faster than the floor.' );
		$this->assertGreaterThanOrEqual( $floor_ns, $miss, 'A miss must not answer faster than the floor.' );
	}

	/**
	 * An overrun is logged rather than hidden, so a store where the floor is too
	 * low for its hardware can be found instead of quietly leaking.
	 *
	 * @return void
	 */
	public function test_an_overrun_of_the_floor_is_logged(): void {
		$this->order( 42 );

		add_filter(
			'wpmphub_lookup_time_floor_ms',
			static function (): int {
				return 0;
			},
			20
		);

		$this->service->attempt( '42', 'jane@example.com', '203.0.113.9' );

		$overruns = array_filter(
			FakeWordPress::$logged,
			static function ( array $line ): bool {
				return 'wpmphub.lookup.floor_overrun' === ( $line['context']['event'] ?? '' );
			}
		);

		$this->assertNotEmpty( $overruns, 'A floor of zero is always overrun and must say so.' );
	}

	/**
	 * Times one call, in nanoseconds.
	 *
	 * @param callable $callback Call to time.
	 * @return int
	 */
	private static function duration( callable $callback ): int {
		$started = hrtime( true );

		$callback();

		return (int) ( hrtime( true ) - $started );
	}
}
