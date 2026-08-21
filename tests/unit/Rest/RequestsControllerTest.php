<?php
/**
 * RequestsController unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Rest\RequestsController;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestLifecycle;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the security-critical rejection paths named in docs/MILESTONE-PROMPTS.md
 * M08 directly: IDOR, rate limiting, cooldown-vs-ineligible status mapping, the
 * guest-token path, and that ineligibility is enforced here — not only by
 * hiding a button — even when the request is forged.
 *
 * @since 0.8.0
 *
 * @covers \PostPurchaseHub\Rest\RequestsController
 */
final class RequestsControllerTest extends TestCase {

	/**
	 * Rate limiter shared with the controller, so tests can pre-exhaust it.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Request-history double backing the controller's EligibilityResolver.
	 *
	 * @var FakeRequestHistory
	 */
	private FakeRequestHistory $history;

	/**
	 * Request-lifecycle double.
	 *
	 * @var FakeRequestLifecycle
	 */
	private FakeRequestLifecycle $service;

	/**
	 * Controller under test.
	 *
	 * @var RequestsController
	 */
	private RequestsController $controller;

	/**
	 * Builds the controller over real security services and fake persistence.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';

		$this->rate_limiter = new RateLimiter( new Cache() );
		$this->history      = new FakeRequestHistory();
		$this->service      = new FakeRequestLifecycle();

		$cancel = new Cancel( new EligibilityResolver( $this->history ), $this->service );

		$this->controller = new RequestsController(
			new OwnershipResolver( new TokenService() ),
			$this->rate_limiter,
			$this->service,
			$cancel,
			new Logger()
		);
	}

	/**
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int    $id          Order id.
	 * @param int    $customer_id Owning customer id, 0 for a guest order.
	 * @param string $status     Unprefixed order status.
	 * @return \WC_Order
	 */
	private function order( int $id, int $customer_id, string $status = 'processing' ): \WC_Order {
		$order = new \WC_Order( $id, $status );
		$order->set_customer_id( $customer_id );
		$order->set_order_key( 'wc_order_key_' . $id );
		$order->set_billing_email( 'customer' . $id . '@example.com' );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Builds a POST /requests request.
	 *
	 * @param int    $order_id    Order id.
	 * @param string $reason_code Reason code.
	 * @param string $note        Note.
	 * @param string $token       Guest token.
	 * @return \WP_REST_Request
	 */
	private function create_request( int $order_id, string $reason_code = 'changed_mind', string $note = '', string $token = '' ): \WP_REST_Request {
		return new \WP_REST_Request(
			array(
				'order_id'    => $order_id,
				'reason_code' => $reason_code,
				'note'        => $note,
				'token'       => $token,
			)
		);
	}

	/**
	 * Runs authorise_create(), returning the order it stashed on success.
	 *
	 * @param \WP_REST_Request $request Request to authorise.
	 * @return \WC_Order
	 */
	private function authorise_create_ok( \WP_REST_Request $request ): \WC_Order {
		$result = $this->controller->authorise_create( $request );

		$this->assertTrue( $result, 'Expected authorise_create() to succeed.' );

		$order = $request->get_param( 'pph_order' );
		$this->assertInstanceOf( \WC_Order::class, $order );

		return $order;
	}

	// -----------------------------------------------------------------
	// register_routes()
	// -----------------------------------------------------------------

	/**
	 * Both routes register with the expected methods and namespace.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_both_routes(): void {
		$this->controller->register_routes();

		$this->assertCount( 2, FakeWordPress::$rest_routes );

		$post = FakeWordPress::$rest_routes[0];
		$this->assertSame( RequestsController::NAMESPACE, $post['namespace'] );
		$this->assertSame( RequestsController::ROUTE, $post['route'] );
		$this->assertSame( 'POST', $post['args']['methods'] );

		$delete = FakeWordPress::$rest_routes[1];
		$this->assertSame( 'DELETE', $delete['args']['methods'] );
		$this->assertStringContainsString( '(?P<id>', $delete['route'] );
	}

	// -----------------------------------------------------------------
	// Nocache (docs/SPEC.md Phase 8: nocache headers on every order-bearing response)
	// -----------------------------------------------------------------

	/**
	 * Authorise_create() marks the response uncacheable.
	 *
	 * Does not assert the constant is undefined beforehand — see the note on
	 * `Security\Tests\SanitizerTest::test_nocache_defines_the_no_cache_constant()`;
	 * the same reasoning applies here, and this file is one of the other
	 * callers that test depends on not assuming a clean slate.
	 *
	 * @return void
	 */
	public function test_authorise_create_sets_nocache(): void {
		$this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * Authorise_withdraw() marks the response uncacheable too.
	 *
	 * @return void
	 */
	public function test_authorise_withdraw_sets_nocache(): void {
		$this->controller->authorise_withdraw(
			new \WP_REST_Request(
				array(
					'id'    => 1,
					'token' => '',
				)
			)
		);

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	// -----------------------------------------------------------------
	// authorise_create() — ownership
	// -----------------------------------------------------------------

	/**
	 * The order's own logged-in customer is authorised.
	 *
	 * @return void
	 */
	public function test_the_owning_customer_is_authorised(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;

		$this->authorise_create_ok( $this->create_request( 10 ) );
	}

	/**
	 * IDOR: a logged-in customer requesting another customer's order is
	 * rejected with a generic 403, not a distinguishable message.
	 *
	 * @return void
	 */
	public function test_a_different_logged_in_customer_is_rejected(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 6;

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'pph_forbidden', $result->get_error_code() );
	}

	/**
	 * A direct-REST forgery against an order id the caller has no relation to
	 * at all is rejected identically to the IDOR case — no distinct signal
	 * that the order exists.
	 *
	 * @return void
	 */
	public function test_a_forged_request_against_an_unrelated_order_is_rejected(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 999;

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A non-existent order id is rejected the same way as a real order the
	 * caller does not own — no existence oracle.
	 *
	 * @return void
	 */
	public function test_a_non_existent_order_is_rejected(): void {
		FakeWordPress::$current_user_id = 5;

		$result = $this->controller->authorise_create( $this->create_request( 404 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A guest with no token is rejected.
	 *
	 * @return void
	 */
	public function test_a_guest_without_a_token_is_rejected(): void {
		$this->order( 10, 0 );

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A guest with a valid signed token is authorised.
	 *
	 * @return void
	 */
	public function test_a_guest_with_a_valid_token_is_authorised(): void {
		$this->order( 10, 0 );
		$token = ( new TokenService() )->issue( 10, 'wc_order_key_10' );

		$this->authorise_create_ok( $this->create_request( 10, 'changed_mind', '', $token ) );
	}

	/**
	 * A guest with a token for a different order is rejected.
	 *
	 * @return void
	 */
	public function test_a_guest_with_a_token_for_another_order_is_rejected(): void {
		$this->order( 10, 0 );
		$this->order( 11, 0 );
		$token = ( new TokenService() )->issue( 11, 'wc_order_key_11' );

		$result = $this->controller->authorise_create( $this->create_request( 10, 'changed_mind', '', $token ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -----------------------------------------------------------------
	// authorise_create() — rate limiting
	// -----------------------------------------------------------------

	/**
	 * Exceeding the per-IP limit throttles with 429, before the order is even loaded.
	 *
	 * @return void
	 */
	public function test_the_ip_rate_limit_returns_429(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->rate_limiter->allow_ip( 'requests_create', '', 10, HOUR_IN_SECONDS );
		}

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( 'pph_rate_limited', $result->get_error_code() );
	}

	/**
	 * Exceeding the per-email limit throttles with 429, after ownership passes.
	 *
	 * @return void
	 */
	public function test_the_email_rate_limit_returns_429(): void {
		$order                          = $this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;

		for ( $i = 0; $i < 5; $i++ ) {
			$this->rate_limiter->allow_email( 'requests_create', $order->get_billing_email(), 5, HOUR_IN_SECONDS );
		}

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * Exceeding the site-wide limit throttles with 429.
	 *
	 * @return void
	 */
	public function test_the_site_rate_limit_returns_429(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$this->rate_limiter->allow_site( 'requests_create', 200, HOUR_IN_SECONDS );
		}

		$result = $this->controller->authorise_create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------
	// create()
	// -----------------------------------------------------------------

	/**
	 * The full happy path: 201, the created request's shape, one create() call.
	 *
	 * @return void
	 */
	public function test_create_returns_201_for_an_eligible_order(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;

		$request = $this->create_request( 10, 'changed_mind', 'Please cancel' );
		$this->authorise_create_ok( $request );

		$response = $this->controller->create( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( Request::TYPE_CANCELLATION, $body['type'] );
		$this->assertSame( Request::STATUS_PENDING, $body['status'] );
		$this->assertSame( 'changed_mind', $body['reason_code'] );
		$this->assertArrayHasKey( 'expected_response_hours', $body );
		$this->assertCount( 1, $this->service->created );
	}

	/**
	 * An order not currently in an eligible status is rejected with 403, even
	 * though authorise_create() (ownership only) already passed — eligibility
	 * is a separate, later check, re-run here regardless of what a client
	 * believes.
	 *
	 * @return void
	 */
	public function test_create_returns_403_for_an_ineligible_order(): void {
		$this->order( 10, 5, 'completed' );
		FakeWordPress::$current_user_id = 5;

		$request = $this->create_request( 10 );
		$this->authorise_create_ok( $request );

		$response = $this->controller->create( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( 'pph_ineligible', $response->get_error_code() );
		$this->assertSame( array(), $this->service->created );
	}

	/**
	 * A second request inside the configured cooldown is rejected with 429,
	 * not 403 — the customer may simply try again once it elapses.
	 *
	 * @return void
	 */
	public function test_create_returns_429_when_the_cooldown_is_still_running(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;

		$this->history->add( 10, Request::TYPE_CANCELLATION, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$request = $this->create_request( 10 );
		$this->authorise_create_ok( $request );

		$response = $this->controller->create( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 429, $response->get_error_data()['status'] );
		$this->assertSame( 'pph_cooldown', $response->get_error_code() );
		$this->assertSame( array(), $this->service->created );
	}

	/**
	 * Every denial carries a support reference id, and the same reference
	 * reaches the log line — a customer reading the error and a merchant
	 * searching the log see the same id.
	 *
	 * @return void
	 */
	public function test_a_denial_carries_a_reference_matching_the_log_line(): void {
		$this->order( 10, 5, 'completed' );
		FakeWordPress::$current_user_id = 5;

		$request = $this->create_request( 10 );
		$this->authorise_create_ok( $request );

		$response = $this->controller->create( $request );

		$reference = $response->get_error_data()['reference'];

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $reference );
		$this->assertNotEmpty( FakeWordPress::$logged );

		$logged = FakeWordPress::$logged[ count( FakeWordPress::$logged ) - 1 ];
		$this->assertSame( $reference, $logged['context']['reference'] );
	}

	/**
	 * A forged direct call with the order-stashing step skipped entirely
	 * (bypassing authorise_create()) still denies rather than fatal or create.
	 *
	 * @return void
	 */
	public function test_create_denies_when_no_order_was_stashed(): void {
		$response = $this->controller->create( $this->create_request( 10 ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->service->created );
	}

	// -----------------------------------------------------------------
	// authorise_withdraw() / withdraw()
	// -----------------------------------------------------------------

	/**
	 * The owning customer may withdraw their own pending request.
	 *
	 * @return void
	 */
	public function test_the_owning_customer_can_withdraw(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;
		$this->service->seed(
			array(
				'id'       => 1,
				'order_id' => 10,
				'type'     => Request::TYPE_CANCELLATION,
			)
		);

		$request = new \WP_REST_Request(
			array(
				'id'    => 1,
				'token' => '',
			)
		);
		$result  = $this->controller->authorise_withdraw( $request );

		$this->assertTrue( $result );

		$response = $this->controller->withdraw( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->service->withdrawn );
	}

	/**
	 * IDOR on withdrawal: a different customer cannot withdraw someone else's request.
	 *
	 * @return void
	 */
	public function test_a_different_customer_cannot_withdraw(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 6;
		$this->service->seed(
			array(
				'id'       => 1,
				'order_id' => 10,
				'type'     => Request::TYPE_CANCELLATION,
			)
		);

		$result = $this->controller->authorise_withdraw(
			new \WP_REST_Request(
				array(
					'id'    => 1,
					'token' => '',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Withdrawing an id that does not exist is rejected, not fatal.
	 *
	 * @return void
	 */
	public function test_withdrawing_an_unknown_id_is_rejected(): void {
		FakeWordPress::$current_user_id = 5;

		$result = $this->controller->authorise_withdraw(
			new \WP_REST_Request(
				array(
					'id'    => 404,
					'token' => '',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A request already resolved cannot be withdrawn again — 409, not a
	 * silent no-op or a second lifecycle event.
	 *
	 * @return void
	 */
	public function test_withdrawing_an_already_resolved_request_returns_409(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;
		$this->service->seed(
			array(
				'id'       => 1,
				'order_id' => 10,
				'type'     => Request::TYPE_CANCELLATION,
				'status'   => Request::STATUS_APPROVED,
			)
		);
		$this->service->withdraw_result = false;

		$request = new \WP_REST_Request(
			array(
				'id'    => 1,
				'token' => '',
			)
		);
		$this->controller->authorise_withdraw( $request );

		$response = $this->controller->withdraw( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}
}
