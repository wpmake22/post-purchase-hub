<?php
/**
 * HelpView unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Emails\HelpRequest;
use PostPurchaseHub\Frontend\HelpView;
use PostPurchaseHub\Frontend\TemplateLoader;
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
 * What the customer sees before submitting: the attached context, rendered
 * from the real template, escaped, once per order — and nothing at all when the
 * store has nowhere to send it.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Frontend\HelpView
 */
final class HelpViewTest extends TestCase {

	/**
	 * View under test.
	 *
	 * @var HelpView
	 */
	private HelpView $view;

	/**
	 * Builds the view over the real action, timeline and template loader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_id = 7;

		$stages   = new StageMap( new StatusDetector( new Cache() ) );
		$timeline = new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) );

		$help = new Help(
			new EligibilityResolver( new FakeRequestHistory() ),
			new HelpContextBuilder( $timeline )
		);

		$this->view = new HelpView( $help, new TemplateLoader( new Logger() ) );
	}

	/**
	 * An order with one line item, owned by the current user.
	 *
	 * @param string $product_name Product name, so a payload can be planted in it.
	 * @return \WC_Order
	 */
	private function order( string $product_name = 'Blue shirt' ): \WC_Order {
		$item = new \WC_Order_Item_Product();
		$item->set_name( $product_name );
		$item->set_quantity( 2 );

		$order = new \WC_Order( 9100, 'processing' );
		$order->set_customer_id( 7 );
		$order->set_billing_email( 'customer@example.test' );
		$order->set_items( array( $item ) );

		FakeWordPress::$orders[9100] = $order;

		return $order;
	}

	/**
	 * Renders the form for an order id.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function render( int $order_id ): string {
		ob_start();

		$this->view->render( $order_id );

		return (string) ob_get_clean();
	}

	/**
	 * The form renders with its own id, the plugin's data attributes and the
	 * order context it will attach.
	 *
	 * @return void
	 */
	public function test_it_renders_the_form_with_its_context(): void {
		$this->order();

		$html = $this->render( 9100 );

		$this->assertStringContainsString( 'data-pph-help', $html );
		$this->assertStringContainsString( 'id="pph-help-9100"', $html );
		$this->assertStringContainsString( 'data-pph-help-form', $html );
		$this->assertStringContainsString( 'data-pph-help-topic', $html );
		$this->assertStringContainsString( 'data-pph-help-message', $html );
		$this->assertStringContainsString( '2 × Blue shirt', $html );
		$this->assertStringContainsString( 'maxlength="' . Help::MESSAGE_MAX_LENGTH . '"', $html );
	}

	/**
	 * The form is a plain `<details>` disclosure, so it opens and reads without
	 * JavaScript — only submitting needs any.
	 *
	 * @return void
	 */
	public function test_the_form_needs_no_javascript_to_read(): void {
		$this->order();

		$html = $this->render( 9100 );

		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( '<summary', $html );
	}

	/**
	 * A product name carrying a payload is escaped on the way out.
	 *
	 * @return void
	 */
	public function test_it_escapes_the_context_it_prints(): void {
		$this->order( '<script>alert(1)</script>' );

		$html = $this->render( 9100 );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'alert(1)', $html );
	}

	/**
	 * One form per order per request, however often the hook fires.
	 *
	 * @return void
	 */
	public function test_it_renders_once_per_order(): void {
		$this->order();

		$this->assertNotSame( '', $this->render( 9100 ) );
		$this->assertSame( '', $this->render( 9100 ), 'A re-fired hook must not draw a second form with the same element id.' );
	}

	/**
	 * With nowhere to send a message, no form is drawn at all.
	 *
	 * @return void
	 */
	public function test_no_form_without_a_destination(): void {
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'enabled' => 'no' );

		$this->order();

		$this->assertSame( '', $this->render( 9100 ) );
	}

	/**
	 * An order id that resolves to nothing renders nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_order_renders_nothing(): void {
		$this->assertSame( '', $this->render( 4242 ) );
	}

	/**
	 * The view hooks the one action every order-detail surface fires, after the
	 * actions list it belongs beneath.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_order_detail_action(): void {
		$this->view->register();

		$this->assertArrayHasKey( 'woocommerce_view_order', FakeWordPress::$actions );
		$this->assertGreaterThan( 25, FakeWordPress::$actions['woocommerce_view_order'][0]['priority'] );
	}
}
