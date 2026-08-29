<?php
/**
 * WP Overnight invoice adapter unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Integrations\Invoices;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Integrations\Invoices\PdfInvoicesPackingSlips;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 3 ) . '/stubs/wp-functions.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/invoices/wpo-wcpdf.php';

/**
 * The fixture case for the one invoice plugin this build adapts to.
 *
 * Everything asserted here is the adapter's own contract with that plugin:
 * read the document, believe `exists()`, take the URL from the plugin's own
 * shortcode, and produce nothing at all on any doubt — because the alternative
 * to "no button" is a broken link on a customer's order page.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Integrations\Invoices\PdfInvoicesPackingSlips
 */
final class PdfInvoicesPackingSlipsTest extends TestCase {

	/**
	 * Adapter under test.
	 *
	 * @var PdfInvoicesPackingSlips
	 */
	private PdfInvoicesPackingSlips $adapter;

	/**
	 * Resets the in-memory WordPress state and the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		\WPMPHUB_Fixture_Wpo_Wcpdf::reset();
		wpmphub_fixture_register_wcpdf_shortcode();

		$this->adapter = new PdfInvoicesPackingSlips();
	}

	/**
	 * An order to resolve against.
	 *
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		return new \WC_Order( 4001, 'completed' );
	}

	/**
	 * The fixture's function presence is what "installed" means.
	 *
	 * @return void
	 */
	public function test_is_active_when_the_plugin_api_is_present(): void {
		$this->assertTrue( $this->adapter->is_active() );
		$this->assertSame( 'wpo-wcpdf', $this->adapter->id() );
	}

	/**
	 * A generated invoice yields the plugin's own URL, and the shortcode is
	 * asked about the right order.
	 *
	 * @return void
	 */
	public function test_returns_the_url_for_a_generated_invoice(): void {
		$url = $this->adapter->url_for( $this->order() );

		$this->assertSame( \WPMPHUB_Fixture_Wpo_Wcpdf::$link_output, $url );
		$this->assertSame( '4001', \WPMPHUB_Fixture_Wpo_Wcpdf::$last_atts['order_id'] ?? '' );
		$this->assertSame( 'invoice', \WPMPHUB_Fixture_Wpo_Wcpdf::$last_atts['document_type'] ?? '' );
	}

	/**
	 * An installed plugin that has generated nothing for this order yields no
	 * link, and is not even asked for one.
	 *
	 * @return void
	 */
	public function test_returns_nothing_when_no_document_exists(): void {
		\WPMPHUB_Fixture_Wpo_Wcpdf::$document_exists = false;

		$this->assertNull( $this->adapter->url_for( $this->order() ) );
		$this->assertSame( array(), \WPMPHUB_Fixture_Wpo_Wcpdf::$last_atts );
	}

	/**
	 * A link returned as an anchor rather than a bare URL still resolves: which
	 * shape the shortcode returns is that plugin's choice, not this adapter's
	 * assumption.
	 *
	 * @return void
	 */
	public function test_reads_the_url_out_of_a_returned_link(): void {
		\WPMPHUB_Fixture_Wpo_Wcpdf::$link_output = '<a href="https://shop.test/invoice.pdf?id=1&amp;key=abc" class="button">Download invoice</a>';

		$this->assertSame( 'https://shop.test/invoice.pdf?id=1&key=abc', $this->adapter->url_for( $this->order() ) );
	}

	/**
	 * Output that is not a URL produces nothing rather than a broken link.
	 *
	 * @return void
	 */
	public function test_returns_nothing_when_the_shortcode_output_is_not_a_url(): void {
		\WPMPHUB_Fixture_Wpo_Wcpdf::$link_output = 'Invoice not available yet';

		$this->assertNull( $this->adapter->url_for( $this->order() ) );
	}

	/**
	 * A missing shortcode — an older version of that plugin — produces nothing.
	 *
	 * @return void
	 */
	public function test_returns_nothing_without_the_link_shortcode(): void {
		FakeWordPress::$shortcodes = array();

		$this->assertNull( $this->adapter->url_for( $this->order() ) );
	}

	/**
	 * A throw from inside the other plugin is contained: an order page must
	 * render either way (CLAUDE.md hard rule 9 — adapters read, and never
	 * become that plugin's error handler).
	 *
	 * @return void
	 */
	public function test_a_throwing_invoice_plugin_produces_nothing(): void {
		\WPMPHUB_Fixture_Wpo_Wcpdf::$throws = true;

		$this->assertNull( $this->adapter->url_for( $this->order() ) );
	}
}
