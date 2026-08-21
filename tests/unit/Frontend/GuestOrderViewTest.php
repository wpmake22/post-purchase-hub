<?php
/**
 * GuestOrderView unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Frontend\GuestContext;
use PostPurchaseHub\Frontend\GuestOrderView;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the last hop of the guest journey, and — more importantly — every way
 * it must refuse to happen.
 *
 * The login form is substituted only for a logged-out visitor, on the
 * `view-order` endpoint, holding a context that `OwnershipResolver` accepts for
 * that exact order. Each of the refusal tests below removes one of those and
 * asserts core's login form survives untouched, because a substitution that is
 * loose in any of those dimensions is an IDOR on the plugin's most sensitive
 * surface.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Frontend\GuestOrderView
 */
final class GuestOrderViewTest extends TestCase {

	/**
	 * View under test.
	 *
	 * @var GuestOrderView
	 */
	private GuestOrderView $view;

	/**
	 * Token issuer.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Path standing in for the core login template WooCommerce resolved.
	 *
	 * @var string
	 */
	private const CORE_LOGIN_PATH = '/woocommerce/templates/myaccount/form-login.php';

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';

		$this->tokens = new TokenService();
		$this->view   = new GuestOrderView(
			new OwnershipResolver( $this->tokens ),
			new TemplateLoader( new Logger() )
		);

		$_GET    = array();
		$_COOKIE = array();
	}

	/**
	 * Clears the superglobals the context reads.
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
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int $id          Order id.
	 * @param int $customer_id Owning customer, 0 for a guest order.
	 * @return \WC_Order
	 */
	private function order( int $id, int $customer_id = 0 ): \WC_Order {
		$order = new \WC_Order( $id, 'processing' );
		$order->set_customer_id( $customer_id );
		$order->set_order_key( 'wc_order_key_' . $id );
		$order->set_billing_email( 'jane@example.com' );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Puts a guest on the view-order endpoint holding a context for one order.
	 *
	 * @param int $context_for Order the context was minted for.
	 * @param int $viewing     Order the URL asks for.
	 * @return void
	 */
	private function guest_viewing( int $context_for, int $viewing ): void {
		$order = FakeWordPress::$orders[ $context_for ];

		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $context_for . '/';
		$_GET[ SecureLink::TOKEN_PARAM ] = $this->tokens->issue( $context_for, (string) $order->get_order_key() );

		$context = new GuestContext( $this->tokens, new Cache(), new Logger() );
		$context->register();
		$context->exchange();

		$_GET = array();

		FakeWordPress::$query_vars['view-order'] = $viewing;
	}

	/**
	 * Runs the filter the way wc_get_template() would.
	 *
	 * @param string $template_name Template being fetched.
	 * @return string
	 */
	private function resolve( string $template_name = GuestOrderView::LOGIN_TEMPLATE ): string {
		return (string) $this->view->replace_login_form( self::CORE_LOGIN_PATH, $template_name );
	}

	/**
	 * A guest holding a valid context for the order they asked for gets the
	 * order instead of a login form they have no password for.
	 *
	 * @return void
	 */
	public function test_a_guest_with_a_context_gets_the_order(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 42 );

		$resolved = $this->resolve();

		$this->assertNotSame( self::CORE_LOGIN_PATH, $resolved );
		$this->assertStringEndsWith( 'templates/myaccount/guest-order-handoff.php', $resolved );
		$this->assertFileExists( $resolved );
	}

	/**
	 * The order page it produces is never cacheable.
	 *
	 * @return void
	 */
	public function test_the_guest_order_page_is_marked_uncacheable(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 42 );
		$this->resolve();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * The rendered order carries the timeline hand-off, core's own view-order
	 * hook and a summary naming the order.
	 *
	 * @return void
	 */
	public function test_it_renders_the_order_through_the_shared_hooks(): void {
		$order = $this->order( 42 );
		$order->set_date( 'date_created', new \WC_DateTime( '2026-03-04 10:00:00' ) );

		$this->guest_viewing( 42, 42 );
		$this->resolve();

		$fired = array();

		foreach ( array( 'pph_render_order_detail', 'pph_render_order_notes', 'woocommerce_view_order' ) as $hook ) {
			add_action(
				$hook,
				static function ( $payload ) use ( &$fired, $hook ): void {
					$fired[ $hook ] = $payload;
				}
			);
		}

		ob_start();
		$this->view->render();
		$markup = (string) ob_get_clean();

		$this->assertSame( $order, $fired['pph_render_order_detail'] ?? null );
		$this->assertSame( $order, $fired['pph_render_order_notes'] ?? null );
		$this->assertSame( 42, $fired['woocommerce_view_order'] ?? null );
		$this->assertStringContainsString( 'data-pph-guest-order="42"', $markup );
		$this->assertStringContainsString( 'data-pph-guest-order-summary', $markup );
	}

	/**
	 * Rendering before anything authorised an order produces nothing, so the
	 * hand-off template cannot be reached by any other route.
	 *
	 * @return void
	 */
	public function test_rendering_without_an_authorised_order_produces_nothing(): void {
		ob_start();
		$this->view->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * A guest with no context at all still gets core's login form.
	 *
	 * @return void
	 */
	public function test_a_guest_without_a_context_gets_the_login_form(): void {
		$this->order( 42 );

		FakeWordPress::$query_vars['view-order'] = 42;

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * A guest holding a context for a different order gets the login form, and
	 * learns nothing about the order they asked for.
	 *
	 * @return void
	 */
	public function test_a_context_for_another_order_gets_the_login_form(): void {
		$this->order( 42 );
		$this->order( 43 );
		$this->guest_viewing( 42, 43 );

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * An order number nobody holds is answered exactly like one that is not
	 * theirs — the login form, with no hint either way.
	 *
	 * @return void
	 */
	public function test_a_nonexistent_order_gets_the_login_form(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 99999 );

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * An expired context gets the login form.
	 *
	 * @return void
	 */
	public function test_an_expired_context_gets_the_login_form(): void {
		$order = $this->order( 42 );

		$_SERVER['REQUEST_URI']          = '/my-account/view-order/42/';
		$_GET[ SecureLink::TOKEN_PARAM ] = $this->tokens->issue( 42, (string) $order->get_order_key(), -60 );

		$context = new GuestContext( $this->tokens, new Cache(), new Logger() );
		$context->register();
		$context->exchange();

		$_GET                                    = array();
		FakeWordPress::$query_vars['view-order'] = 42;

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * Rotating the order key revokes the context, so the guest is back to the
	 * login form.
	 *
	 * @return void
	 */
	public function test_rotating_the_order_key_returns_the_login_form(): void {
		$order = $this->order( 42 );
		$this->guest_viewing( 42, 42 );

		$order->set_order_key( 'wc_order_key_rotated' );

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * Any other template WooCommerce fetches is left alone, even mid-request
	 * for an authorised guest.
	 *
	 * @return void
	 */
	public function test_it_substitutes_no_other_template(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 42 );

		foreach ( array( 'myaccount/view-order.php', 'order/order-details.php', 'myaccount/form-lost-password.php' ) as $other ) {
			$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve( $other ), $other . ' must not be substituted.' );
		}
	}

	/**
	 * A logged-in visitor is never diverted, whatever cookie they carry: core
	 * does not render a login form for them and this must not start.
	 *
	 * @return void
	 */
	public function test_a_logged_in_visitor_is_never_diverted(): void {
		$this->order( 42, 7 );
		$this->guest_viewing( 42, 42 );

		FakeWordPress::$current_user_id = 7;

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * A request that is not on the view-order endpoint is left alone.
	 *
	 * @return void
	 */
	public function test_a_request_outside_the_endpoint_gets_the_login_form(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 42 );

		FakeWordPress::$query_vars = array();

		$this->assertSame( self::CORE_LOGIN_PATH, $this->resolve() );
	}

	/**
	 * An expired link is explained rather than silently answered with a login
	 * form, and the message names no order.
	 *
	 * @return void
	 */
	public function test_an_expired_link_is_explained(): void {
		$_GET[ GuestContext::STATE_PARAM ] = GuestContext::STATE_EXPIRED;

		$message = $this->view->explain_dead_link( '' );

		$this->assertStringContainsString( 'expired', $message );
		$this->assertStringNotContainsString( '42', $message );
	}

	/**
	 * A browser that dropped the cookie is told that, rather than being told
	 * the link expired when it did not.
	 *
	 * @return void
	 */
	public function test_a_dropped_cookie_is_explained(): void {
		$_GET[ GuestContext::STATE_PARAM ] = GuestContext::STATE_READY;

		$this->assertStringContainsString( 'cookie', $this->view->explain_dead_link( '' ) );
	}

	/**
	 * A guest whose context did work is told nothing: the order is about to
	 * render, so a cookie warning would be both wrong and alarming.
	 *
	 * @return void
	 */
	public function test_a_working_context_is_not_warned_about_cookies(): void {
		$this->order( 42 );
		$this->guest_viewing( 42, 42 );

		$_GET[ GuestContext::STATE_PARAM ] = GuestContext::STATE_READY;

		$this->assertSame( '', $this->view->explain_dead_link( '' ) );
	}

	/**
	 * A message another plugin already set is left alone.
	 *
	 * @return void
	 */
	public function test_it_does_not_overwrite_another_plugins_message(): void {
		$_GET[ GuestContext::STATE_PARAM ] = GuestContext::STATE_EXPIRED;

		$this->assertSame( 'Someone else was here.', $this->view->explain_dead_link( 'Someone else was here.' ) );
	}

	/**
	 * An ordinary visit to My Account says nothing.
	 *
	 * @return void
	 */
	public function test_an_ordinary_account_visit_is_not_annotated(): void {
		$this->assertSame( '', $this->view->explain_dead_link( '' ) );
	}

	/**
	 * The filter and the hand-off are both registered.
	 *
	 * @return void
	 */
	public function test_it_registers_the_substitution_and_the_handoff(): void {
		$this->view->register();

		$this->assertArrayHasKey( 'wc_get_template', FakeWordPress::$filters );
		$this->assertArrayHasKey( 'pph_render_guest_order', FakeWordPress::$actions );
		$this->assertArrayHasKey( 'woocommerce_my_account_message', FakeWordPress::$filters );
	}
}
