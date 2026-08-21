<?php
/**
 * ReorderView unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderPlanner;
use PostPurchaseHub\Frontend\ReorderView;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Actions\FakeCart;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers what the customer actually sees, and the two things that must not
 * happen while they are looking at it: no cart mutation, and no second
 * unreconciled reorder button beside it.
 *
 * @since 0.12.0
 *
 * @covers \PostPurchaseHub\Frontend\ReorderView
 */
final class ReorderViewTest extends TestCase {

	/**
	 * Cart double.
	 *
	 * @var FakeCart
	 */
	private FakeCart $cart;

	/**
	 * View under test.
	 *
	 * @var ReorderView
	 */
	private ReorderView $view;

	/**
	 * Builds the view over a real planner, template loader and fake cart.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_id = 7;

		$this->cart = new FakeCart();

		$reorder = new Reorder(
			new EligibilityResolver( new FakeRequestHistory() ),
			new ReorderPlanner(),
			$this->cart
		);

		$this->view = new ReorderView( $reorder, new TemplateLoader( new Logger() ) );
	}

	/**
	 * Clears the query argument between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_GET[ Reorder::QUERY_ARG ] );

		parent::tearDown();
	}

	/**
	 * An order with one buyable line and one that is gone.
	 *
	 * @param int    $id     Order id.
	 * @param string $status Unprefixed order status.
	 * @return \WC_Order
	 */
	private function order( int $id = 300, string $status = 'completed' ): \WC_Order {
		$product = new \WC_Product( 'simple', 3001 );
		$product->set_name( 'Espresso beans' );
		$product->set_price( 12.5 );
		$product->set_permalink( 'https://example.test/espresso' );

		FakeWordPress::$products[3001] = $product;

		$buyable = new \WC_Order_Item_Product( $product );
		$buyable->set_product_id( 3001 );
		$buyable->set_quantity( 2 );
		$buyable->set_subtotal( 20.0 );
		$buyable->set_name( 'Espresso beans' );

		$gone = new \WC_Order_Item_Product( null );
		$gone->set_product_id( 3002 );
		$gone->set_quantity( 1 );
		$gone->set_subtotal( 5.0 );
		$gone->set_name( 'Discontinued mug' );

		$order = new \WC_Order( $id, $status );
		$order->set_customer_id( 7 );
		$order->set_items( array( $buyable, $gone ) );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Renders the view for an order and returns the markup.
	 *
	 * @param int $order_id Order id passed to the hook.
	 * @return string
	 */
	private function render( int $order_id ): string {
		ob_start();
		$this->view->render( $order_id );

		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------
	// When the summary renders
	// -----------------------------------------------------------------

	/**
	 * Without the query argument the order page is left exactly as it was.
	 *
	 * @return void
	 */
	public function test_nothing_renders_without_the_query_argument(): void {
		$this->order();

		$this->assertSame( '', $this->render( 300 ) );
	}

	/**
	 * The argument has to name the order whose page is being rendered, so it
	 * can never be used to ask about somebody else's order.
	 *
	 * @return void
	 */
	public function test_nothing_renders_for_a_different_order_id(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '999';

		$this->assertSame( '', $this->render( 300 ) );
	}

	/**
	 * An ineligible order renders no summary even when asked directly.
	 *
	 * @return void
	 */
	public function test_nothing_renders_for_an_ineligible_order(): void {
		$this->order( 300, 'processing' );
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$this->assertSame( '', $this->render( 300 ) );
	}

	/**
	 * A logged-out visitor gets no summary.
	 *
	 * @return void
	 */
	public function test_nothing_renders_for_a_logged_out_visitor(): void {
		FakeWordPress::$current_user_id = 0;
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$this->assertSame( '', $this->render( 300 ) );
	}

	// -----------------------------------------------------------------
	// What the summary says
	// -----------------------------------------------------------------

	/**
	 * Every line is stated explicitly, with its outcome, and the cart is not
	 * touched by drawing it.
	 *
	 * @return void
	 */
	public function test_the_summary_states_every_line_and_touches_no_cart(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$html = $this->render( 300 );

		$this->assertStringContainsString( 'data-pph-reorder', $html );
		$this->assertStringContainsString( 'Espresso beans', $html );
		$this->assertStringContainsString( 'Discontinued mug', $html );
		$this->assertStringContainsString( 'data-pph-reorder-outcome="added"', $html );
		$this->assertStringContainsString( 'data-pph-reorder-outcome="unavailable"', $html );
		$this->assertStringContainsString( 'data-pph-reorder-confirm', $html );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * A price change is printed with both prices and the difference, never
	 * hidden.
	 *
	 * @return void
	 */
	public function test_a_price_change_is_printed_with_its_delta(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$html = $this->render( 300 );

		$this->assertStringContainsString( 'Price changed', $html );
		$this->assertStringContainsString( '$10.00', $html );
		$this->assertStringContainsString( '$12.50', $html );
		$this->assertStringContainsString( '+$2.50', $html );
	}

	/**
	 * The merge-or-replace choice appears only when there is a cart to lose.
	 *
	 * @return void
	 */
	public function test_the_mode_choice_appears_only_with_a_non_empty_cart(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$empty_cart_html = $this->render( 300 );

		$this->assertStringNotContainsString( 'type="radio"', $empty_cart_html );
		$this->assertStringContainsString( 'data-pph-reorder-mode-default', $empty_cart_html );

		$this->cart->existing = 2;
		$fresh                = new ReorderView(
			new Reorder( new EligibilityResolver( new FakeRequestHistory() ), new ReorderPlanner(), $this->cart ),
			new TemplateLoader( new Logger() )
		);

		ob_start();
		$fresh->render( 300 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="merge"', $html );
		$this->assertStringContainsString( 'value="replace"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
	}

	/**
	 * An order where nothing can be bought again says so and offers no
	 * confirmation at all.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_available_offers_no_confirmation(): void {
		$order = $this->order();

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product() instanceof \WC_Product ) {
				$item->get_product()->set_max_purchase_quantity( 0 );
			}
		}

		$_GET[ Reorder::QUERY_ARG ] = '300';

		$html = $this->render( 300 );

		$this->assertStringContainsString( 'data-pph-reorder-unavailable', $html );
		$this->assertStringNotContainsString( 'data-pph-reorder-confirm', $html );
		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * The summary is drawn once per order, however often the hook fires.
	 *
	 * @return void
	 */
	public function test_the_summary_renders_once_per_order(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$this->assertNotSame( '', $this->render( 300 ) );
		$this->assertSame( '', $this->render( 300 ) );
	}

	/**
	 * The summary is an order-bearing response, so it is never cacheable.
	 *
	 * @return void
	 */
	public function test_the_summary_marks_the_page_uncacheable(): void {
		$this->order();
		$_GET[ Reorder::QUERY_ARG ] = '300';

		$this->render( 300 );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	// -----------------------------------------------------------------
	// Core's own button
	// -----------------------------------------------------------------

	/**
	 * Registers core's reorder button the way WooCommerce does.
	 *
	 * @return void
	 */
	private function register_core_button(): void {
		add_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
	}

	/**
	 * Whether core's button is still registered.
	 *
	 * @return bool
	 */
	private function core_button_registered(): bool {
		foreach ( FakeWordPress::$actions['woocommerce_order_details_after_order_table'] ?? array() as $registered ) {
			if ( 'woocommerce_order_again_button' === $registered['callback'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * For an order this plugin offers a reconciled reorder on, core's
	 * one-click button goes away.
	 *
	 * @return void
	 */
	public function test_core_button_is_removed_for_an_eligible_order(): void {
		$this->order();
		$this->register_core_button();

		$this->view->supersede_core_button( 300 );

		$this->assertFalse( $this->core_button_registered() );
	}

	/**
	 * For an excluded order type, core's button goes away too: an excluded
	 * order must offer no reorder anywhere, not just none of ours.
	 *
	 * @return void
	 */
	public function test_core_button_is_removed_for_an_excluded_order(): void {
		$order = $this->order();
		$order->set_type( 'shop_subscription' );
		$this->register_core_button();

		$this->view->supersede_core_button( 300 );

		$this->assertFalse( $this->core_button_registered() );
	}

	/**
	 * For an order this plugin has no opinion about, core's page is left
	 * alone.
	 *
	 * @return void
	 */
	public function test_core_button_survives_for_an_order_we_do_not_speak_for(): void {
		$this->order( 300, 'processing' );
		$this->register_core_button();

		$this->view->supersede_core_button( 300 );

		$this->assertTrue( $this->core_button_registered() );
	}

	/**
	 * A merchant can opt out of the replacement and keep both buttons.
	 *
	 * @return void
	 */
	public function test_the_replacement_is_filterable(): void {
		$this->order();
		$this->register_core_button();

		add_filter( 'pph_reorder_supersedes_core_button', static fn (): bool => false );

		$this->view->supersede_core_button( 300 );

		$this->assertTrue( $this->core_button_registered() );
	}

	/**
	 * Both hooks are wired, and the summary lands after the timeline.
	 *
	 * @return void
	 */
	public function test_register_wires_both_hooks(): void {
		$this->view->register();

		$priorities = array();

		foreach ( FakeWordPress::$actions['woocommerce_view_order'] ?? array() as $registered ) {
			$priorities[] = $registered['priority'];
		}

		$this->assertContains( 5, $priorities, 'Core button removal must run before the order table renders.' );
		$this->assertContains( 22, $priorities, 'The summary must render after the timeline.' );
	}
}
