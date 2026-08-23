<?php
/**
 * The pre-setup silence.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Rest\SetupController;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__ ) . '/stubs/wp-functions.php';

/**
 * The hard requirement of docs/MILESTONE-PROMPTS.md M14, asserted at the level
 * it is enforced: "NOTHING renders on the frontend until the wizard completes. A
 * plugin that silently rewrites the customer order page on activation is a
 * plugin that gets uninstalled."
 *
 * The test is about hooks and routes rather than markup, because that is where
 * the gate is: an unconfigured store wires none of the storefront and registers
 * none of the customer-facing REST routes, so there is nothing for a theme, a
 * template or a forged request to reach.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Plugin
 */
final class SetupGateTest extends TestCase {

	/**
	 * Hooks the storefront is drawn from. None of them may be wired before
	 * setup completes.
	 *
	 * @var string[]
	 */
	private const STOREFRONT_HOOKS = array(
		'woocommerce_view_order',
		'woocommerce_my_account_my_orders_actions',
		'woocommerce_my_account_my_orders_column_pph_timeline',
		'wp_enqueue_scripts',
		'wp_footer',
		'wc_get_template',
		'template_include',
	);

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
	 * Marks setup finished.
	 *
	 * @return void
	 */
	private function complete_setup(): void {
		FakeWordPress::$options[ SetupState::OPTION ] = array(
			'step'         => SetupState::FINAL_STEP,
			'completed_at' => '2026-08-21 00:00:00',
		);
	}

	/**
	 * Every hook wired by a storefront request, as a flat list.
	 *
	 * @return array<int, string>
	 */
	private function wired_hooks(): array {
		return array_merge( array_keys( FakeWordPress::$actions ), array_keys( FakeWordPress::$filters ) );
	}

	/**
	 * A fresh install wires no storefront hook at all.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_store_wires_no_storefront_hook(): void {
		( new Plugin() )->register_rendering();

		$wired = $this->wired_hooks();

		foreach ( self::STOREFRONT_HOOKS as $hook ) {
			$this->assertNotContains(
				$hook,
				$wired,
				$hook . ' must not be wired before the wizard completes.'
			);
		}
	}

	/**
	 * The shortcode and the block *are* registered, though — an unregistered
	 * shortcode prints its own raw text at customers, which is worse than the
	 * nothing an unconfigured store is meant to show.
	 *
	 * @return void
	 */
	public function test_the_shortcodes_are_still_registered_so_nothing_leaks(): void {
		( new Plugin() )->register_rendering();

		$this->assertNotSame( array(), FakeWordPress::$shortcodes, 'The shortcodes stay registered and silent.' );
	}

	/**
	 * No customer-facing REST route exists before setup: a button that is not
	 * drawn is not a control if the route behind it still answers.
	 *
	 * The wizard's own routes are the deliberate exception — they are how a
	 * store stops being unconfigured — so the assertion is that nothing *else*
	 * is registered, and that every route that is carries an administrator-only
	 * permission callback rather than a customer-facing one.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_store_registers_only_the_wizard_routes(): void {
		( new Plugin() )->register_rest_routes();

		$this->assertNotSame( array(), FakeWordPress::$rest_routes, 'The wizard has to be reachable before setup, or setup can never happen.' );

		foreach ( FakeWordPress::$rest_routes as $route ) {
			$this->assertStringStartsWith(
				SetupController::ROUTE,
				$route['route'],
				'Only the setup wizard may register a route before setup completes.'
			);

			$this->assertSame(
				array( 'authorise' ),
				array( array_slice( (array) $route['args']['permission_callback'], 1, 1 )[0] ),
				'Every wizard route is gated on the same administrator check.'
			);
		}
	}

	/**
	 * Once setup completes, the storefront comes up.
	 *
	 * @return void
	 */
	public function test_a_configured_store_wires_the_storefront(): void {
		$this->complete_setup();

		( new Plugin() )->register_rendering();

		$wired = $this->wired_hooks();

		$this->assertContains( 'woocommerce_view_order', $wired );
		$this->assertContains( 'woocommerce_my_account_my_orders_actions', $wired );
		$this->assertContains( 'wp_enqueue_scripts', $wired );
	}

	/**
	 * And so do the routes.
	 *
	 * @return void
	 */
	public function test_a_configured_store_registers_its_routes(): void {
		$this->complete_setup();

		( new Plugin() )->register_rest_routes();

		$routes = array_map(
			static function ( array $route ): string {
				return $route['route'];
			},
			FakeWordPress::$rest_routes
		);

		$this->assertContains( '/requests', $routes );
		$this->assertContains( '/help', $routes );
		$this->assertContains( '/reorder', $routes );
	}

	/**
	 * A store configured outside the wizard — provisioned by a script, or by a
	 * settings array in a site's own code — opts in through the filter and gets
	 * the storefront without ever seeing the wizard.
	 *
	 * @return void
	 */
	public function test_the_filter_opens_the_storefront_too(): void {
		FakeWordPress::$filters['pph_setup_complete'][] = static function (): bool {
			return true;
		};

		( new Plugin() )->register_rendering();

		$this->assertContains( 'woocommerce_view_order', $this->wired_hooks() );
	}
}
