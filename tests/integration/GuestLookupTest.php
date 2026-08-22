<?php
/**
 * Guest lookup and signed-link integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Frontend\GuestContext;
use PostPurchaseHub\Frontend\GuestOrderView;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;

/**
 * Exercises what only a real WooCommerce can: order-number and billing-email
 * matching through actual CRUD, so the behaviour is proven identical with HPOS
 * on and off, and the full token-to-context-to-ownership chain against a real
 * order key.
 *
 * The order-key rotation test is the one that could not be written any other
 * way. docs/SPEC.md Phase 8 promises rotating an order's key revokes every
 * outstanding link; that promise is only worth anything if a saved order really
 * does return the new key through both storage backends.
 *
 * Not executed in the session that wrote it — this environment has no
 * `WP_TESTS_DIR` / wp-env WordPress test library available, so `composer
 * test:int` could not be run here. Written to the conventions of
 * `EmailsTest` so it runs the moment wp-env is available; see the milestone
 * report's Tests section.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Security\OrderLookup
 * @covers \PostPurchaseHub\Security\GuestLookupService
 * @covers \PostPurchaseHub\Frontend\GuestContext
 */
final class GuestLookupTest extends \WP_UnitTestCase {

	/**
	 * Turns guest lookup on and installs a token secret.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// The plugin's own GuestContext registered `pph_current_request_token`
		// at bootstrap, and it memoises the first token it resolves for the
		// life of the process. Every test here mints a fresh secret and builds
		// its own context, so that ambient one answers each later test with a
		// token that no longer decodes — and because it registered first, its
		// answer is the one that wins. Clearing it in tear_down does not work:
		// WP_UnitTestCase snapshots the hooks in set_up and restores them
		// afterwards, which puts it straight back.
		remove_all_filters( 'pph_current_request_token' );

		update_option( Activator::TOKEN_SECRET_OPTION, bin2hex( random_bytes( 64 ) ), '', false );

		update_option(
			'pph_settings',
			array(
				GuestAccess::ENABLED_SETTING      => true,
				GuestAccess::ACKNOWLEDGED_SETTING => true,
			),
			false
		);

		add_filter(
			'pph_lookup_time_floor_ms',
			static function (): int {
				return 0;
			}
		);

		$_GET    = array();
		$_COOKIE = array();
	}

	/**
	 * Clears what the exchange wrote.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET    = array();
		$_COOKIE = array();

		unset( $_SERVER['REQUEST_URI'] );

		parent::tear_down();
	}

	/**
	 * Runs only the mail-send callback the service queued.
	 *
	 * Deliberately not `do_action( 'shutdown' )`: firing WordPress's real
	 * shutdown hook inside a test would flush the output buffers PHPUnit is
	 * holding. Only this plugin's own callback, at the priority it registers
	 * with, is invoked.
	 *
	 * @return void
	 */
	private function run_queued_sends(): void {
		$hooked = $GLOBALS['wp_filter']['shutdown'] ?? null;

		if ( ! $hooked instanceof \WP_Hook ) {
			return;
		}

		foreach ( $hooked->callbacks[ PHP_INT_MAX - 1 ] ?? array() as $callback ) {
			if ( is_callable( $callback['function'] ) ) {
				call_user_func( $callback['function'] );
			}
		}

		$hooked->callbacks[ PHP_INT_MAX - 1 ] = array();
	}

	/**
	 * A real order, saved through CRUD so both storage backends are exercised.
	 *
	 * @param string $billing_email Billing email address.
	 * @return \WC_Order
	 */
	private function order( string $billing_email = 'customer@example.test' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_billing_email( $billing_email );
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * The service under test, over real collaborators.
	 *
	 * @return GuestLookupService
	 */
	private function service(): GuestLookupService {
		return new GuestLookupService(
			new GuestAccess(),
			new OrderLookup(),
			new RateLimiter( new Cache() ),
			new Logger()
		);
	}

	/**
	 * A matching pair resolves through real CRUD.
	 *
	 * @return void
	 */
	public function test_a_matching_pair_resolves_a_real_order(): void {
		$order = $this->order();

		$found = ( new OrderLookup() )->find( (string) $order->get_order_number(), 'customer@example.test' );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * The wrong address resolves nothing, whichever storage backend is in use.
	 *
	 * @return void
	 */
	public function test_the_wrong_email_resolves_nothing(): void {
		$order = $this->order();

		$this->assertNull( ( new OrderLookup() )->find( (string) $order->get_order_number(), 'attacker@example.test' ) );
	}

	/**
	 * Sequential probing past the highest real order finds nothing.
	 *
	 * @return void
	 */
	public function test_probing_past_the_last_order_resolves_nothing(): void {
		$order = $this->order();

		$this->assertNull(
			( new OrderLookup() )->find( (string) ( $order->get_id() + 1000 ), 'customer@example.test' )
		);
	}

	/**
	 * A match queues a link for the address on the order, and a mismatch
	 * queues nothing at all.
	 *
	 * @return void
	 */
	public function test_only_a_match_queues_a_link_for_the_order(): void {
		$order  = $this->order();
		$mailed = array();

		add_action(
			'pph_secure_link_requested',
			static function ( $subject ) use ( &$mailed ): void {
				$mailed[] = $subject->get_id();
			}
		);

		$this->service()->attempt( (string) $order->get_order_number(), 'customer@example.test', '203.0.113.1' );
		$this->run_queued_sends();

		$this->assertSame( array( $order->get_id() ), $mailed );

		$this->service()->attempt( (string) $order->get_order_number(), 'attacker@example.test', '203.0.113.2' );
		$this->run_queued_sends();

		$this->assertSame( array( $order->get_id() ), $mailed, 'A mismatched address must add nothing.' );
	}

	/**
	 * The whole chain: an emailed token becomes a cookie context, and the
	 * ownership resolver lets that context reach the order it names.
	 *
	 * @return void
	 */
	public function test_a_token_becomes_a_context_the_resolver_accepts(): void {
		$order   = $this->order();
		$tokens  = new TokenService();
		$context = new GuestContext( $tokens, new Cache(), new Logger() );

		wp_set_current_user( 0 );

		$_GET[ SecureLink::TOKEN_PARAM ] = $tokens->issue( $order->get_id(), (string) $order->get_order_key() );
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $order->get_id() . '/';

		$context->register();
		$context->exchange();

		$resolved = ( new OwnershipResolver( $tokens ) )->assertCanAccess( $order->get_id(), 'test:guest-context' );

		$this->assertSame( $order->get_id(), $resolved->get_id() );
	}

	/**
	 * That same context reaches only the order its token names.
	 *
	 * @return void
	 */
	public function test_a_context_reaches_only_its_own_order(): void {
		$mine     = $this->order();
		$somebody = $this->order( 'someone-else@example.test' );
		$tokens   = new TokenService();
		$context  = new GuestContext( $tokens, new Cache(), new Logger() );

		wp_set_current_user( 0 );

		$_GET[ SecureLink::TOKEN_PARAM ] = $tokens->issue( $mine->get_id(), (string) $mine->get_order_key() );
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $mine->get_id() . '/';

		$context->register();
		$context->exchange();

		$this->expectException( AccessDeniedException::class );

		( new OwnershipResolver( $tokens ) )->assertCanAccess( $somebody->get_id(), 'test:guest-context-idor' );
	}

	/**
	 * Rotating the order key revokes an outstanding context, which is the
	 * revocation path docs/SPEC.md Phase 8 promises.
	 *
	 * @return void
	 */
	public function test_rotating_the_order_key_revokes_an_existing_context(): void {
		$order   = $this->order();
		$tokens  = new TokenService();
		$context = new GuestContext( $tokens, new Cache(), new Logger() );

		wp_set_current_user( 0 );

		$_GET[ SecureLink::TOKEN_PARAM ] = $tokens->issue( $order->get_id(), (string) $order->get_order_key() );
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $order->get_id() . '/';

		$context->register();
		$context->exchange();

		$order->set_order_key( 'wc_order_' . wp_generate_password( 13, false ) );
		$order->save();

		$this->expectException( AccessDeniedException::class );

		( new OwnershipResolver( $tokens ) )->assertCanAccess( $order->get_id(), 'test:revoked' );
	}

	/**
	 * The journey's last hop against real WooCommerce: a guest holding a
	 * context gets the order in place of core's login form, and a guest without
	 * one does not.
	 *
	 * This is the test that would have caught the milestone's original gap.
	 * `WC_Shortcode_My_Account::output()` answers a logged-out visitor with
	 * `myaccount/form-login.php` and offers no filter on that branch, so a valid
	 * token opened a password prompt rather than an order.
	 *
	 * @return void
	 */
	public function test_a_guest_with_a_context_is_shown_the_order_not_the_login_form(): void {
		$order   = $this->order();
		$tokens  = new TokenService();
		$context = new GuestContext( $tokens, new Cache(), new Logger() );
		$view    = new GuestOrderView( new OwnershipResolver( $tokens ), new TemplateLoader( new Logger() ) );

		wp_set_current_user( 0 );

		$_GET[ SecureLink::TOKEN_PARAM ] = $tokens->issue( $order->get_id(), (string) $order->get_order_key() );
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $order->get_id() . '/';

		$context->register();
		$context->exchange();

		$_GET = array();
		set_query_var( 'view-order', $order->get_id() );

		$login = wc_locate_template( GuestOrderView::LOGIN_TEMPLATE );

		$this->assertNotSame(
			$login,
			$view->replace_login_form( $login, GuestOrderView::LOGIN_TEMPLATE ),
			'A guest holding a valid context must not be shown a login form.'
		);
	}

	/**
	 * The same request without a context keeps core's login form.
	 *
	 * @return void
	 */
	public function test_a_guest_without_a_context_keeps_the_login_form(): void {
		$order = $this->order();
		$view  = new GuestOrderView( new OwnershipResolver( new TokenService() ), new TemplateLoader( new Logger() ) );

		wp_set_current_user( 0 );
		set_query_var( 'view-order', $order->get_id() );

		$login = wc_locate_template( GuestOrderView::LOGIN_TEMPLATE );

		$this->assertSame( $login, $view->replace_login_form( $login, GuestOrderView::LOGIN_TEMPLATE ) );
	}

	/**
	 * The exchange strips the token from where it sends the visitor next.
	 *
	 * @return void
	 */
	public function test_the_exchange_strips_the_token_from_the_target(): void {
		$order  = $this->order();
		$tokens = new TokenService();
		$token  = $tokens->issue( $order->get_id(), (string) $order->get_order_key() );

		$_GET[ SecureLink::TOKEN_PARAM ] = $token;
		$_SERVER['REQUEST_URI']          = '/my-account/view-order/' . $order->get_id() . '/?' . SecureLink::TOKEN_PARAM . '=' . rawurlencode( $token );

		$target = ( new GuestContext( $tokens, new Cache(), new Logger() ) )->exchange();

		$this->assertIsString( $target );
		$this->assertStringNotContainsString( SecureLink::TOKEN_PARAM, (string) $target );
	}
}
