<?php
/**
 * HelpController unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContext;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Emails\HelpRequest;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Rest\HelpController;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The route's rejection paths, and M13's rate-limit test: another customer's
 * order, an exhausted limit, a topic outside the whitelist, and the fact that
 * a submission needs POST.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Rest\HelpController
 */
final class HelpControllerTest extends TestCase {

	/**
	 * Rate limiter shared with the controller, so tests can exhaust it.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Controller under test.
	 *
	 * @var HelpController
	 */
	private HelpController $controller;

	/**
	 * Submissions recorded from the hand-off action.
	 *
	 * @var array<int, HelpContext>
	 */
	private array $submitted = array();

	/**
	 * Builds the controller over the real security services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';
		$_SERVER['REMOTE_ADDR']                                   = '203.0.113.7';

		$this->submitted    = array();
		$this->rate_limiter = new RateLimiter( new Cache() );

		$stages   = new StageMap( new StatusDetector( new Cache() ) );
		$timeline = new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) );

		$this->controller = new HelpController(
			new OwnershipResolver( new TokenService() ),
			$this->rate_limiter,
			new Help( new EligibilityResolver( new FakeRequestHistory() ), new HelpContextBuilder( $timeline ) ),
			new Logger()
		);

		$recorder = function ( HelpContext $context ): void {
			$this->submitted[] = $context;
		};

		FakeWordPress::$actions['pph_help_submitted'][] = array(
			'callback' => $recorder,
			'priority' => 10,
		);
	}

	/**
	 * Stores a fake order.
	 *
	 * @param int $id          Order id.
	 * @param int $customer_id Owning customer id.
	 * @return \WC_Order
	 */
	private function order( int $id, int $customer_id ): \WC_Order {
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Blue shirt' );
		$item->set_quantity( 1 );

		$order = new \WC_Order( $id, 'processing' );
		$order->set_customer_id( $customer_id );
		$order->set_order_key( 'wc_order_key_' . $id );
		$order->set_billing_email( 'customer' . $id . '@example.com' );
		$order->set_items( array( $item ) );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Builds a POST /help request.
	 *
	 * @param int    $order_id Order id.
	 * @param string $topic    Topic code.
	 * @param string $message  Message body.
	 * @return \WP_REST_Request
	 */
	private function request( int $order_id, string $topic = 'other', string $message = 'Please help.' ): \WP_REST_Request {
		return new \WP_REST_Request(
			array(
				'order_id' => $order_id,
				'topic'    => $topic,
				'message'  => $message,
			)
		);
	}

	/**
	 * The route is POST only, with a real permission callback and a validated,
	 * sanitised schema per field.
	 *
	 * @return void
	 */
	public function test_the_route_is_post_only_with_a_real_permission_callback(): void {
		$this->controller->register_routes();

		$this->assertCount( 1, FakeWordPress::$rest_routes );

		$route = FakeWordPress::$rest_routes[0];

		$this->assertSame( HelpController::NAMESPACE, $route['namespace'] );
		$this->assertSame( HelpController::ROUTE, $route['route'] );
		$this->assertSame( 'POST', $route['args']['methods'] );
		$this->assertNotSame( '__return_true', $route['args']['permission_callback'] );
		$this->assertIsArray( $route['args']['permission_callback'] );

		foreach ( array( 'order_id', 'topic', 'message', 'token' ) as $field ) {
			$this->assertArrayHasKey( 'validate_callback', $route['args']['args'][ $field ] );
			$this->assertArrayHasKey( 'sanitize_callback', $route['args']['args'][ $field ] );
		}
	}

	/**
	 * The topic field accepts only this install's vocabulary.
	 *
	 * @return void
	 */
	public function test_the_topic_field_rejects_anything_else(): void {
		$this->controller->register_routes();

		$topic = FakeWordPress::$rest_routes[0]['args']['args']['topic'];

		$this->assertTrue( $topic['validate_callback']( 'other' ) );
		$this->assertFalse( $topic['validate_callback']( 'refund_me_now' ) );
		$this->assertFalse( $topic['validate_callback']( array( 'other' ) ) );
	}

	/**
	 * An absurd message is refused by the schema before the action sees it.
	 *
	 * @return void
	 */
	public function test_the_message_field_has_an_upper_bound(): void {
		$this->controller->register_routes();

		$message = FakeWordPress::$rest_routes[0]['args']['args']['message'];

		$this->assertTrue( $message['validate_callback']( 'Hello' ) );
		$this->assertFalse( $message['validate_callback']( str_repeat( 'a', Help::MESSAGE_MAX_LENGTH * 4 + 1 ) ) );
	}

	/**
	 * The order's own customer is authorised, and the order is stashed for the
	 * callback rather than loaded twice.
	 *
	 * @return void
	 */
	public function test_the_orders_own_customer_is_authorised(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 30, 5 );

		$request = $this->request( 30 );

		$this->assertTrue( $this->controller->authorise( $request ) );
		$this->assertInstanceOf( \WC_Order::class, $request->get_param( 'pph_order' ) );
	}

	/**
	 * Another customer's order is refused, with the same message a missing
	 * order gets — no existence oracle.
	 *
	 * @return void
	 */
	public function test_another_customers_order_is_refused(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 31, 99 );

		$denied  = $this->controller->authorise( $this->request( 31 ) );
		$missing = $this->controller->authorise( $this->request( 4242 ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'pph_forbidden', $denied->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $denied->get_error_message() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( array(), $this->submitted );
	}

	/**
	 * A guest holding a valid signed link may ask for help — the whole point of
	 * the guest flow, and the reason the route takes a token at all.
	 *
	 * @return void
	 */
	public function test_a_guest_with_a_signed_token_is_authorised(): void {
		$order  = $this->order( 32, 0 );
		$tokens = new TokenService();
		$token  = $tokens->issue( $order->get_id(), $order->get_order_key() );

		$request = new \WP_REST_Request(
			array(
				'order_id' => 32,
				'topic'    => 'other',
				'message'  => 'Where is it?',
				'token'    => $token,
			)
		);

		$this->assertTrue( $this->controller->authorise( $request ) );

		$response = $this->controller->submit( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertCount( 1, $this->submitted );
		$this->assertSame( Help::SOURCE_GUEST, $this->submitted[0]->source );
	}

	/**
	 * The rate limit is enforced, and the response says so rather than leaking
	 * why.
	 *
	 * @return void
	 */
	public function test_the_rate_limit_is_enforced(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 33, 5 );

		$allowed = 0;

		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$result = $this->controller->authorise( $this->request( 33 ) );

			if ( true === $result ) {
				++$allowed;
				continue;
			}

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'pph_rate_limited', $result->get_error_code() );
			$this->assertSame( 429, $result->get_error_data()['status'] );

			break;
		}

		$this->assertGreaterThan( 0, $allowed, 'The first submissions are allowed through.' );
		$this->assertLessThan( 12, $allowed, 'The route is throttled well before a dozen attempts.' );
	}

	/**
	 * A successful submission hands off and answers with a confirmation that
	 * does not echo the customer's own words back.
	 *
	 * @return void
	 */
	public function test_a_submission_is_handed_off(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 34, 5 );

		$request = $this->request( 34, 'where_is_my_order', 'It has not arrived.' );

		$this->assertTrue( $this->controller->authorise( $request ) );

		$response = $this->controller->submit( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['submitted'] );
		$this->assertStringNotContainsString( 'It has not arrived.', (string) $data['message'] );
		$this->assertCount( 1, $this->submitted );
		$this->assertSame( 'It has not arrived.', $this->submitted[0]->message );
	}

	/**
	 * With nowhere to send it, the route refuses rather than accepting a
	 * message that would go nowhere.
	 *
	 * @return void
	 */
	public function test_a_store_with_no_destination_refuses(): void {
		FakeWordPress::$current_user_id                         = 5;
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'enabled' => 'no' );

		$this->order( 35, 5 );

		$request = $this->request( 35 );

		$this->assertTrue( $this->controller->authorise( $request ) );

		$error = $this->controller->submit( $request );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( array(), $this->submitted );
	}

	/**
	 * Server-side enforcement, not UI hiding: a topic the whitelist does not
	 * carry is refused even when it reaches the callback directly.
	 *
	 * @return void
	 */
	public function test_an_unknown_topic_is_refused_at_the_callback(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 36, 5 );

		$request = $this->request( 36, 'refund_me_now' );

		$this->assertTrue( $this->controller->authorise( $request ) );

		$error = $this->controller->submit( $request );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( array(), $this->submitted );
	}
}
