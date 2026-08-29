<?php
/**
 * ReorderController unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderOptions;
use PostPurchaseHub\Actions\ReorderPlanner;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Rest\ReorderController;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Actions\FakeCart;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the route's rejection paths: another customer's order, a forged
 * confirmation for an ineligible order, an exhausted rate limit — and that the
 * route is POST-only, since a cart-filling GET is the thing this whole
 * milestone is built to avoid.
 *
 * @since 0.12.0
 *
 * @covers \PostPurchaseHub\Rest\ReorderController
 */
final class ReorderControllerTest extends TestCase {

	/**
	 * Rate limiter shared with the controller, so tests can pre-exhaust it.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Cart double.
	 *
	 * @var FakeCart
	 */
	private FakeCart $cart;

	/**
	 * Controller under test.
	 *
	 * @var ReorderController
	 */
	private ReorderController $controller;

	/**
	 * Builds the controller over real security services and a fake cart.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';

		$this->rate_limiter = new RateLimiter( new Cache() );
		$this->cart         = new FakeCart();

		$reorder = new Reorder(
			new EligibilityResolver( new FakeRequestHistory() ),
			new ReorderPlanner(),
			$this->cart
		);

		$this->controller = new ReorderController(
			new OwnershipResolver( new TokenService() ),
			$this->rate_limiter,
			$reorder,
			$this->cart,
			new Logger()
		);
	}

	/**
	 * Stores a fake order with one buyable line.
	 *
	 * @param int    $id          Order id.
	 * @param int    $customer_id Owning customer id.
	 * @param string $status      Unprefixed order status.
	 * @return \WC_Order
	 */
	private function order( int $id, int $customer_id, string $status = 'completed' ): \WC_Order {
		$product = new \WC_Product( 'simple', $id * 10 );
		$product->set_name( 'Product' );
		$product->set_price( 10.0 );
		$product->set_permalink( 'https://example.test/product' );

		FakeWordPress::$products[ $id * 10 ] = $product;

		$item = new \WC_Order_Item_Product( $product );
		$item->set_product_id( $id * 10 );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10.0 );
		$item->set_name( 'Product' );

		$order = new \WC_Order( $id, $status );
		$order->set_customer_id( $customer_id );
		$order->set_order_key( 'wc_order_key_' . $id );
		$order->set_billing_email( 'customer' . $id . '@example.com' );
		$order->set_items( array( $item ) );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Builds a POST /reorder request.
	 *
	 * @param int    $order_id Order id.
	 * @param string $mode     Cart mode.
	 * @return \WP_REST_Request
	 */
	private function request( int $order_id, string $mode = ReorderOptions::MODE_MERGE ): \WP_REST_Request {
		return new \WP_REST_Request(
			array(
				'order_id' => $order_id,
				'mode'     => $mode,
			)
		);
	}

	// -----------------------------------------------------------------
	// register_routes()
	// -----------------------------------------------------------------

	/**
	 * The route registers as POST only, with a real permission callback and a
	 * validated, sanitised schema per field.
	 *
	 * @return void
	 */
	public function test_the_route_is_post_only_with_a_real_permission_callback(): void {
		$this->controller->register_routes();

		$this->assertCount( 1, FakeWordPress::$rest_routes );

		$route = FakeWordPress::$rest_routes[0];

		$this->assertSame( ReorderController::NAMESPACE, $route['namespace'] );
		$this->assertSame( ReorderController::ROUTE, $route['route'] );
		$this->assertSame( 'POST', $route['args']['methods'] );
		$this->assertNotSame( '__return_true', $route['args']['permission_callback'] );
		$this->assertIsArray( $route['args']['permission_callback'] );

		foreach ( array( 'order_id', 'mode' ) as $field ) {
			$this->assertArrayHasKey( 'validate_callback', $route['args']['args'][ $field ] );
			$this->assertArrayHasKey( 'sanitize_callback', $route['args']['args'][ $field ] );
		}
	}

	/**
	 * The mode field accepts only the two modes, whatever is posted.
	 *
	 * @return void
	 */
	public function test_the_mode_field_rejects_anything_else(): void {
		$this->controller->register_routes();

		$mode = FakeWordPress::$rest_routes[0]['args']['args']['mode'];

		$this->assertTrue( $mode['validate_callback']( 'replace' ) );
		$this->assertFalse( $mode['validate_callback']( 'obliterate' ) );
		$this->assertSame( ReorderOptions::MODE_MERGE, $mode['sanitize_callback']( 'obliterate' ) );
	}

	// -----------------------------------------------------------------
	// authorise()
	// -----------------------------------------------------------------

	/**
	 * The order's own customer is authorised, and the order is stashed for the
	 * callback rather than loaded twice.
	 *
	 * @return void
	 */
	public function test_the_orders_own_customer_is_authorised(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 20, 5 );

		$request = $this->request( 20 );

		$this->assertTrue( $this->controller->authorise( $request ) );
		$this->assertInstanceOf( \WC_Order::class, $request->get_param( 'wpmphub_order' ) );
	}

	/**
	 * Another customer's order is refused, with the same message a missing
	 * order gets.
	 *
	 * @return void
	 */
	public function test_another_customers_order_is_refused(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 21, 99 );

		$denied  = $this->controller->authorise( $this->request( 21 ) );
		$missing = $this->controller->authorise( $this->request( 4242 ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'wpmphub_forbidden', $denied->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $denied->get_error_message() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
	}

	/**
	 * A signed guest token authorises reaching the order but not reordering
	 * it: the cart needs an account.
	 *
	 * @return void
	 */
	public function test_a_guest_token_cannot_reorder(): void {
		$order = $this->order( 22, 0 );
		$token = ( new TokenService() )->issue( $order->get_id(), $order->get_order_key() );

		add_filter( 'wpmphub_current_request_token', static fn (): string => $token );

		$request = $this->request( 22 );

		$this->assertTrue( $this->controller->authorise( $request ), 'The token should still authorise reaching the order.' );

		$denied = $this->controller->confirm( $request );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * The response is marked uncacheable before anything else happens.
	 *
	 * @return void
	 */
	public function test_authorise_sets_nocache(): void {
		$this->controller->authorise( $this->request( 1 ) );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * An exhausted per-IP budget refuses with 429 before the order is loaded.
	 *
	 * @return void
	 */
	public function test_an_exhausted_ip_budget_is_refused(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 23, 5 );

		for ( $i = 0; $i < 40; $i++ ) {
			$result = $this->controller->authorise( $this->request( 23 ) );

			if ( $result instanceof \WP_Error ) {
				$this->assertSame( 'wpmphub_rate_limited', $result->get_error_code() );
				$this->assertSame( 429, $result->get_error_data()['status'] );

				return;
			}
		}

		$this->fail( 'The rate limiter never refused a request.' );
	}

	/**
	 * The per-email limit refuses across changing IPs.
	 *
	 * The IP dimension alone stops one machine; docs/SPEC.md Phase 8 names
	 * reorder in its rate-limiting row because a botnet spreading one attempt
	 * per address is exactly the shape of cart abuse this route invites.
	 *
	 * @return void
	 */
	public function test_the_email_budget_is_refused_across_changing_ips(): void {
		FakeWordPress::$current_user_id = 5;
		$order                          = $this->order( 24, 5 );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->rate_limiter->allow_email( 'reorder', $order->get_billing_email(), 10, HOUR_IN_SECONDS );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';

		$result = $this->controller->authorise( $this->request( 24 ) );

		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wpmphub_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * The site-wide backstop refuses even a first-time identity.
	 *
	 * @return void
	 */
	public function test_the_site_budget_is_refused(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 25, 5 );

		for ( $i = 0; $i < 300; $i++ ) {
			$this->rate_limiter->allow_site( 'reorder', 300, HOUR_IN_SECONDS );
		}

		$result = $this->controller->authorise( $this->request( 25 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------
	// confirm()
	// -----------------------------------------------------------------

	/**
	 * A confirmed reorder updates the cart and answers with where to go next.
	 *
	 * @return void
	 */
	public function test_a_confirmed_reorder_updates_the_cart(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 24, 5 );

		$request = $this->request( 24 );
		$this->controller->authorise( $request );

		$response = $this->controller->confirm( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( ReorderOptions::MODE_MERGE, $data['mode'] );
		$this->assertSame( 1, $data['added_count'] );
		$this->assertSame( 0, $data['rejected_count'] );
		$this->assertSame( 'https://example.test/cart/', $data['cart_url'] );
		$this->assertCount( 1, $this->cart->added );
	}

	/**
	 * A forged confirmation for an ineligible order is refused at the server,
	 * not merely un-rendered in the UI.
	 *
	 * @return void
	 */
	public function test_a_forged_confirmation_for_an_ineligible_order_is_refused(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 25, 5, 'processing' );

		$request = $this->request( 25 );
		$this->controller->authorise( $request );

		$denied = $this->controller->confirm( $request );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'wpmphub_ineligible', $denied->get_error_code() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * An order with nothing buyable left gets its own code and message, and
	 * leaves the cart alone.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_available_is_refused_clearly(): void {
		FakeWordPress::$current_user_id = 5;
		$order                          = $this->order( 26, 5 );

		foreach ( $order->get_items() as $item ) {
			$item->get_product()->set_max_purchase_quantity( 0 );
		}

		$request = $this->request( 26, ReorderOptions::MODE_REPLACE );
		$this->controller->authorise( $request );

		$denied = $this->controller->confirm( $request );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'wpmphub_nothing_available', $denied->get_error_code() );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * The callback refuses to act on a request that never carried an
	 * authorised order, rather than trusting the id it was given.
	 *
	 * @return void
	 */
	public function test_confirm_without_an_authorised_order_is_refused(): void {
		$denied = $this->controller->confirm( $this->request( 27 ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'wpmphub_forbidden', $denied->get_error_code() );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * The response body carries no prices — the summary the customer already
	 * read is where those belong.
	 *
	 * @return void
	 */
	public function test_the_response_body_carries_no_prices(): void {
		FakeWordPress::$current_user_id = 5;
		$this->order( 28, 5 );

		$request = $this->request( 28 );
		$this->controller->authorise( $request );

		$data = $this->controller->confirm( $request )->get_data();

		$this->assertSame( array( 'name', 'outcome', 'quantity' ), array_keys( $data['added'][0] ) );
	}
}
