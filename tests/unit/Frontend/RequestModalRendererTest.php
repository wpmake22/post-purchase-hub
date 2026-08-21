<?php
/**
 * RequestModalRenderer unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Frontend\Assets;
use PostPurchaseHub\Frontend\RequestModalRenderer;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers rendering the modal exactly where the assets that make it
 * interactive already load, and nowhere else.
 *
 * @since 0.8.0
 *
 * @covers \PostPurchaseHub\Frontend\RequestModalRenderer
 */
final class RequestModalRendererTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var RequestModalRenderer
	 */
	private RequestModalRenderer $renderer;

	/**
	 * Builds the renderer over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->renderer = new RequestModalRenderer( new TemplateLoader( new Logger() ), new Assets() );
	}

	/**
	 * On a page Assets would not load on, nothing renders.
	 *
	 * @return void
	 */
	public function test_nothing_renders_where_assets_are_not_required(): void {
		FakeWordPress::$post = new \WP_Post( 'Just some content.' );

		ob_start();
		$this->renderer->render();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * On an order endpoint, the modal renders with the reason select populated.
	 *
	 * @return void
	 */
	public function test_the_modal_renders_on_an_order_endpoint(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'orders' );

		ob_start();
		$this->renderer->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-pph-request-modal', $html );
		$this->assertStringContainsString( 'data-pph-request-reason', $html );

		foreach ( Cancel::reason_code_labels() as $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html );
		}
	}

	/**
	 * Register() wires the footer hook.
	 *
	 * @return void
	 */
	public function test_register_wires_the_footer_hook(): void {
		$this->renderer->register();

		$this->assertArrayHasKey( 'wp_footer', FakeWordPress::$actions );
	}
}
