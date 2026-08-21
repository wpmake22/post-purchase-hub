<?php
/**
 * LookupController unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Rest\LookupController;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The enumeration test docs/MILESTONE-PROMPTS.md M11 asks for, plus the route's
 * own contract.
 *
 * The interesting assertion is `test_paired_requests_are_indistinguishable`: it
 * walks fifty order numbers, half of which exist, submitting a mismatched email
 * for every one, and demands that the responses be byte-identical. That is the
 * property an attacker attacks, so it is the property with a test that fails
 * loudly rather than a comment claiming it holds.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Rest\LookupController
 */
final class LookupControllerTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var LookupController
	 */
	private LookupController $controller;

	/**
	 * Builds the controller over the real security services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		$this->enable();

		$this->controller = new LookupController(
			new GuestAccess(),
			new GuestLookupService(
				new GuestAccess(),
				new OrderLookup(),
				new RateLimiter( new Cache() ),
				new Logger()
			)
		);

		add_filter(
			'pph_lookup_time_floor_ms',
			static function (): int {
				return 2;
			}
		);
	}

	/**
	 * Turns guest lookup on.
	 *
	 * @return void
	 */
	private function enable(): void {
		FakeWordPress::$options['pph_settings'] = array(
			GuestAccess::ENABLED_SETTING      => true,
			GuestAccess::ACKNOWLEDGED_SETTING => true,
		);
	}

	/**
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int    $id    Order id.
	 * @param string $email Billing email.
	 * @return void
	 */
	private function order( int $id, string $email = 'jane@example.com' ): void {
		$order = new \WC_Order( $id, 'processing' );
		$order->set_billing_email( $email );

		FakeWordPress::$orders[ $id ] = $order;
	}

	/**
	 * Runs one attempt through the controller.
	 *
	 * @param string $number Submitted order number.
	 * @param string $email  Submitted email.
	 * @return \WP_REST_Response
	 */
	private function attempt( string $number, string $email ): \WP_REST_Response {
		return $this->controller->lookup(
			new \WP_REST_Request(
				array(
					'order_number' => $number,
					'email'        => $email,
				)
			)
		);
	}

	/**
	 * The route is registered with a real permission callback and a schema on
	 * every field, per CLAUDE.md hard rule 3.
	 *
	 * @return void
	 */
	public function test_the_route_declares_a_permission_callback_and_a_full_schema(): void {
		$this->controller->register_routes();

		$this->assertCount( 1, FakeWordPress::$rest_routes );

		$route = FakeWordPress::$rest_routes[0];

		$this->assertSame( LookupController::NAMESPACE, $route['namespace'] );
		$this->assertSame( LookupController::ROUTE, $route['route'] );
		$this->assertNotSame( '__return_true', $route['args']['permission_callback'] );
		$this->assertIsCallable( $route['args']['permission_callback'] );

		foreach ( array( 'order_number', 'email' ) as $field ) {
			$this->assertArrayHasKey( 'validate_callback', $route['args']['args'][ $field ] );
			$this->assertArrayHasKey( 'sanitize_callback', $route['args']['args'][ $field ] );
		}
	}

	/**
	 * A store that has not enabled lookup does not advertise the route at all.
	 *
	 * @return void
	 */
	public function test_a_disabled_store_registers_no_route(): void {
		FakeWordPress::$options['pph_settings'] = array();

		$this->controller->register_routes();

		$this->assertSame( array(), FakeWordPress::$rest_routes );
	}

	/**
	 * The permission callback refuses a disabled store even if the route were
	 * somehow reached.
	 *
	 * @return void
	 */
	public function test_the_permission_callback_refuses_a_disabled_store(): void {
		FakeWordPress::$options['pph_settings'] = array();

		$denial = $this->controller->authorise( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $denial );
		$this->assertSame( 'pph_lookup_unavailable', $denial->get_error_code() );
	}

	/**
	 * Every order-bearing response is marked uncacheable before anything else
	 * happens.
	 *
	 * @return void
	 */
	public function test_the_permission_callback_marks_the_response_uncacheable(): void {
		$this->controller->authorise( new \WP_REST_Request() );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * The submitted schema refuses what it should.
	 *
	 * @dataProvider provide_invalid_submissions
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Candidate value.
	 * @return void
	 */
	public function test_the_schema_refuses_invalid_input( string $field, $value ): void {
		$this->controller->register_routes();

		$validate = FakeWordPress::$rest_routes[0]['args']['args'][ $field ]['validate_callback'];

		$this->assertFalse( (bool) $validate( $value ) );
	}

	/**
	 * Values the schema must reject.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public function provide_invalid_submissions(): array {
		return array(
			'empty order number'   => array( 'order_number', '   ' ),
			'over-long number'     => array( 'order_number', str_repeat( '9', OrderLookup::MAX_NUMBER_LENGTH + 1 ) ),
			'array as number'      => array( 'order_number', array( '42' ) ),
			'not an email'         => array( 'email', 'jane at example dot com' ),
			'email with no domain' => array( 'email', 'jane@' ),
			'array as email'       => array( 'email', array( 'jane@example.com' ) ),
			'over-long email'      => array( 'email', str_repeat( 'a', 250 ) . '@example.com' ),
		);
	}

	/**
	 * A matched pair answers 200 with the one message.
	 *
	 * @return void
	 */
	public function test_a_matched_pair_answers_with_the_shared_message(): void {
		$this->order( 42 );

		$response = $this->attempt( '42', 'jane@example.com' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'message' => GuestLookupService::accepted_message() ), $response->get_data() );
	}

	/**
	 * The response body names one key and one only. A field reporting whether a
	 * link was sent would be the oracle; this test is what stops one appearing.
	 *
	 * @return void
	 */
	public function test_the_response_body_carries_nothing_but_a_message(): void {
		$this->order( 42 );

		$this->assertSame( array( 'message' ), array_keys( (array) $this->attempt( '42', 'jane@example.com' )->get_data() ) );
	}

	/**
	 * Fifty paired requests — half against orders that exist, half against
	 * order numbers that do not, every one with a mismatched email — produce one
	 * distinct response between them.
	 *
	 * @return void
	 */
	public function test_paired_requests_are_indistinguishable(): void {
		add_filter(
			'pph_lookup_time_floor_ms',
			static function (): int {
				return 0;
			},
			20
		);

		$pairs = 50;

		for ( $id = 1; $id <= $pairs; $id += 2 ) {
			$this->order( $id, 'owner' . $id . '@example.com' );
		}

		$responses = array();

		for ( $id = 1; $id <= $pairs; $id++ ) {
			// A fresh IP and a fresh address per attempt. This test is about the
			// response shape, and a throttle would mask it by answering 429 for
			// both halves of the pair — which is the rate limiter working, and
			// is asserted separately below.
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $id;

			$response = $this->attempt( (string) $id, 'attacker' . $id . '@example.com' );

			$responses[] = array(
				'status' => $response->get_status(),
				'body'   => wp_json_encode( $response->get_data() ),
			);
		}

		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertCount( $pairs, $responses );
		$this->assertCount(
			1,
			array_unique( array_map( 'serialize', $responses ) ),
			'An existing order and a non-existent one must be one response, not two.'
		);
	}

	/**
	 * The same fifty pairs, timed: the mean duration of a request that hits an
	 * order and one that does not must sit inside the same envelope.
	 *
	 * Sampled rather than exhaustive, and with a tolerance rather than an
	 * equality, because a shared CI runner's scheduler is noisier than the
	 * signal being measured. What it does catch is the regression that matters:
	 * expensive work — a mail send, an order load — landing outside the floor.
	 *
	 * @return void
	 */
	public function test_a_hit_and_a_miss_share_a_timing_envelope(): void {
		$this->order( 1, 'owner@example.com' );

		$floor_ms = 8;

		add_filter(
			'pph_lookup_time_floor_ms',
			static function () use ( $floor_ms ): int {
				return $floor_ms;
			},
			20
		);

		$hits   = array();
		$misses = array();

		for ( $sample = 0; $sample < 8; $sample++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $sample;

			$hits[] = self::duration( fn() => $this->attempt( '1', 'hit' . $sample . '@example.com' ) );

			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $sample;

			$misses[] = self::duration( fn() => $this->attempt( '999999', 'miss' . $sample . '@example.com' ) );
		}

		unset( $_SERVER['REMOTE_ADDR'] );

		$hit_mean  = array_sum( $hits ) / count( $hits );
		$miss_mean = array_sum( $misses ) / count( $misses );

		$this->assertGreaterThanOrEqual( $floor_ms, $hit_mean, 'A hit must not answer faster than the floor.' );
		$this->assertGreaterThanOrEqual( $floor_ms, $miss_mean, 'A miss must not answer faster than the floor.' );
		$this->assertLessThan(
			$floor_ms,
			abs( $hit_mean - $miss_mean ),
			'The gap between a hit and a miss must stay well inside the floor that hides it.'
		);
	}

	/**
	 * A throttled attempt answers 429 with the generic message — distinguishable
	 * on purpose, because it describes the requester and not any order.
	 *
	 * @return void
	 */
	public function test_a_throttled_attempt_answers_429(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		for ( $attempt = 0; $attempt <= GuestLookupService::IP_LIMIT; $attempt++ ) {
			$response = $this->attempt( '999', 'jane@example.com' );
		}

		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array( 'message' => GuestLookupService::throttled_message() ), $response->get_data() );
	}

	/**
	 * Times one call, in milliseconds.
	 *
	 * @param callable $callback Call to time.
	 * @return float
	 */
	private static function duration( callable $callback ): float {
		$started = hrtime( true );

		$callback();

		return ( hrtime( true ) - $started ) / 1000000;
	}
}
