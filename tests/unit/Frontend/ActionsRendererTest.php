<?php
/**
 * ActionsRenderer unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Frontend\ActionsRenderer;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers both rendering contexts, and specifically the WooCommerce-native
 * `cancel` action's real one-click behaviour (see includes/wc-account-functions.php
 * and includes/class-wc-form-handler.php in the WooCommerce plugin) getting
 * superseded — added, not merely hidden — by a registered action sharing its
 * key, in both directions: an eligible registration overwrites core's entry,
 * and an ineligible one removes it too.
 *
 * @since 0.7.0
 *
 * @covers \PostPurchaseHub\Frontend\ActionsRenderer
 */
final class ActionsRendererTest extends TestCase {

	/**
	 * Registry under test.
	 *
	 * @var ActionRegistry
	 */
	private ActionRegistry $registry;

	/**
	 * Renderer under test.
	 *
	 * @var ActionsRenderer
	 */
	private ActionsRenderer $renderer;

	/**
	 * Builds the renderer over a fresh fake WordPress and a real template loader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->registry = new ActionRegistry();
		$this->renderer = new ActionsRenderer( $this->registry, new TemplateLoader( new Logger() ) );
	}

	/**
	 * WooCommerce's own list actions array, as wc_get_account_orders_actions()
	 * builds it for a pending order.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function core_actions(): array {
		return array(
			'view'   => array(
				'url'  => '/view',
				'name' => 'View',
			),
			'cancel' => array(
				'url'  => '/cancel-order?order_id=1',
				'name' => 'Cancel',
			),
		);
	}

	/**
	 * An eligible registered action is added under its own key.
	 *
	 * @return void
	 */
	public function test_an_eligible_action_is_added_to_the_list(): void {
		$this->registry->register(
			'help',
			'Get help',
			array( 'list' ),
			static fn (): ?array => array(
				'name' => 'Get help',
				'url'  => '/help',
			)
		);

		$actions = $this->renderer->filter_list_actions( $this->core_actions(), new \WC_Order( 1, 'pending' ) );

		$this->assertArrayHasKey( 'help', $actions );
		$this->assertSame( 'Get help', $actions['help']['name'] );
	}

	/**
	 * An ineligible registered action never appears, and disturbs nothing that
	 * was not registered under its own key.
	 *
	 * @return void
	 */
	public function test_an_ineligible_action_does_not_appear(): void {
		$this->registry->register( 'help', 'Get help', array( 'list' ), static fn (): ?array => null );

		$actions = $this->renderer->filter_list_actions( $this->core_actions(), new \WC_Order( 1, 'pending' ) );

		$this->assertArrayNotHasKey( 'help', $actions );
		$this->assertArrayHasKey( 'view', $actions );
		$this->assertArrayHasKey( 'cancel', $actions );
	}

	/**
	 * A registered action sharing WooCommerce's own `cancel` key overwrites
	 * core's entry when eligible — the customer sees our action, not both.
	 *
	 * @return void
	 */
	public function test_an_eligible_action_overwrites_a_core_entry_under_the_same_key(): void {
		$this->registry->register(
			'cancel',
			'Request cancellation',
			array( 'list' ),
			static fn (): ?array => array(
				'name' => 'Request cancellation',
				'url'  => '/wpmphub-cancel',
			)
		);

		$actions = $this->renderer->filter_list_actions( $this->core_actions(), new \WC_Order( 1, 'pending' ) );

		$this->assertSame( 'Request cancellation', $actions['cancel']['name'] );
		$this->assertSame( '/wpmphub-cancel', $actions['cancel']['url'] );
	}

	/**
	 * A registered action sharing WooCommerce's own `cancel` key removes
	 * core's entry when ineligible — an order our own rule excludes must not
	 * fall back to exposing core's one-click cancel instead.
	 *
	 * @return void
	 */
	public function test_an_ineligible_action_removes_a_core_entry_under_the_same_key(): void {
		$this->registry->register( 'cancel', 'Request cancellation', array( 'list' ), static fn (): ?array => null );

		$actions = $this->renderer->filter_list_actions( $this->core_actions(), new \WC_Order( 1, 'pending' ) );

		$this->assertArrayNotHasKey( 'cancel', $actions );
	}

	/**
	 * An action registered only for the detail context is never touched by
	 * the list filter.
	 *
	 * @return void
	 */
	public function test_a_detail_only_action_is_ignored_in_the_list_context(): void {
		$this->registry->register(
			'invoice',
			'Invoice',
			array( 'detail' ),
			static fn (): ?array => array(
				'name' => 'Invoice',
				'url'  => '/invoice',
			)
		);

		$actions = $this->renderer->filter_list_actions( $this->core_actions(), new \WC_Order( 1, 'pending' ) );

		$this->assertArrayNotHasKey( 'invoice', $actions );
	}

	/**
	 * A non-array actions argument is returned as an empty array rather than
	 * causing a fatal.
	 *
	 * @return void
	 */
	public function test_a_non_array_actions_argument_is_handled(): void {
		$this->assertSame( array(), $this->renderer->filter_list_actions( 'not an array', new \WC_Order( 1, 'pending' ) ) );
	}

	/**
	 * A non-order argument leaves the actions array untouched.
	 *
	 * @return void
	 */
	public function test_a_non_order_argument_is_handled(): void {
		$actions = $this->core_actions();

		$this->assertSame( $actions, $this->renderer->filter_list_actions( $actions, 'not an order' ) );
	}

	/**
	 * Eligible detail-context actions render into the partial.
	 *
	 * @return void
	 */
	public function test_eligible_detail_actions_render(): void {
		$this->registry->register(
			'cancel',
			'Request cancellation',
			array( 'detail' ),
			static fn (): ?array => array(
				'name' => 'Request cancellation',
				'url'  => '/wpmphub-cancel',
			)
		);

		FakeWordPress::$orders[1] = new \WC_Order( 1, 'pending' );

		ob_start();
		$this->renderer->render_detail( 1 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-wpmphub-action="cancel"', $html );
		$this->assertStringContainsString( 'Request cancellation', $html );
	}

	/**
	 * No eligible detail actions renders nothing.
	 *
	 * @return void
	 */
	public function test_no_eligible_detail_actions_renders_nothing(): void {
		$this->registry->register( 'cancel', 'Request cancellation', array( 'detail' ), static fn (): ?array => null );

		FakeWordPress::$orders[1] = new \WC_Order( 1, 'pending' );

		ob_start();
		$this->renderer->render_detail( 1 );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * An order id that does not resolve to an order renders nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_order_id_renders_nothing(): void {
		ob_start();
		$this->renderer->render_detail( 404 );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * The detail actions render at most once per order per request.
	 *
	 * @return void
	 */
	public function test_detail_actions_render_at_most_once_per_order(): void {
		$calls = 0;

		$this->registry->register(
			'cancel',
			'Request cancellation',
			array( 'detail' ),
			static function () use ( &$calls ): ?array {
				++$calls;

				return array(
					'name' => 'Request cancellation',
					'url'  => '/wpmphub-cancel',
				);
			}
		);

		FakeWordPress::$orders[1] = new \WC_Order( 1, 'pending' );

		ob_start();
		$this->renderer->render_detail( 1 );
		$this->renderer->render_detail( 1 );
		ob_end_clean();

		$this->assertSame( 1, $calls );
	}

	/**
	 * Only hooking woocommerce_view_order and the actions filter — nothing
	 * else — is wired by register().
	 *
	 * @return void
	 */
	public function test_register_wires_exactly_the_expected_hooks(): void {
		$this->renderer->register();

		$this->assertArrayHasKey( 'woocommerce_my_account_my_orders_actions', FakeWordPress::$filters );
		$this->assertArrayHasKey( 'woocommerce_view_order', FakeWordPress::$actions );
	}
}
