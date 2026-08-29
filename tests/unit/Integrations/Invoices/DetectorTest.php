<?php
/**
 * Invoice detector unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Integrations\Invoices;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Invoices\InvoiceSource;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 3 ) . '/stubs/wp-functions.php';

/**
 * Covers docs/MILESTONE-PROMPTS.md M13's detector cases: a fixture per
 * supported invoice plugin, the none case, and the caching the milestone asks
 * for.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Integrations\Invoices\Detector
 * @covers \PostPurchaseHub\Integrations\Invoices\InvoiceSource
 */
final class DetectorTest extends TestCase {

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
	 * An order to resolve against.
	 *
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		$order = new \WC_Order( 4001, 'completed' );
		$order->set_billing_email( 'customer@example.test' );

		return $order;
	}

	/**
	 * A detector over an explicit provider list.
	 *
	 * @param FakeInvoiceProvider ...$providers Providers to try, in order.
	 * @return Detector
	 */
	private function detector( FakeInvoiceProvider ...$providers ): Detector {
		return new Detector( new Cache(), $providers );
	}

	/**
	 * The none case: nothing installed means no source, and nothing invented.
	 *
	 * @return void
	 */
	public function test_no_installed_plugin_yields_no_source(): void {
		$detector = $this->detector( new FakeInvoiceProvider( 'none-here', false ) );

		$this->assertNull( $detector->detect() );
		$this->assertNull( $detector->source_for( $this->order() ) );
	}

	/**
	 * An installed plugin with a document for this order yields its URL.
	 *
	 * @return void
	 */
	public function test_active_provider_with_a_document_yields_its_url(): void {
		$provider = new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/invoice/4001.pdf' );
		$source   = $this->detector( $provider )->source_for( $this->order() );

		$this->assertInstanceOf( InvoiceSource::class, $source );
		$this->assertSame( 'https://shop.test/invoice/4001.pdf', $source->url );
		$this->assertSame( 'fixture-a', $source->provider );
		$this->assertTrue( $source->is_document() );
	}

	/**
	 * An installed plugin that has generated nothing for this order yet is
	 * detected, but yields no link — the case M13's first acceptance criterion
	 * is written about.
	 *
	 * @return void
	 */
	public function test_active_provider_without_a_document_yields_no_source(): void {
		$provider = new FakeInvoiceProvider( 'fixture-a', true, null );
		$detector = $this->detector( $provider );

		$this->assertNotNull( $detector->detect() );
		$this->assertNull( $detector->source_for( $this->order() ) );
	}

	/**
	 * The first active provider wins, and the inactive one before it is not
	 * asked for a URL.
	 *
	 * @return void
	 */
	public function test_the_first_active_provider_is_used(): void {
		$inactive = new FakeInvoiceProvider( 'inactive', false, 'https://shop.test/never.pdf' );
		$active   = new FakeInvoiceProvider( 'active', true, 'https://shop.test/yes.pdf' );

		$source = $this->detector( $inactive, $active )->source_for( $this->order() );

		$this->assertNotNull( $source );
		$this->assertSame( 'active', $source->provider );
		$this->assertSame( 0, $inactive->url_calls );
	}

	/**
	 * Detection is cached: a second detector over the same cache does not
	 * re-probe.
	 *
	 * @return void
	 */
	public function test_detection_is_cached_across_instances(): void {
		$cache = new Cache();
		$first = new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/a.pdf' );

		( new Detector( $cache, array( $first ) ) )->detect();

		$second = new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/a.pdf' );

		( new Detector( $cache, array( $second ) ) )->detect();

		$this->assertSame( 1, $first->active_calls, 'The first detector probes.' );
		$this->assertSame(
			1,
			$second->active_calls,
			'The second confirms the cached provider is still live, rather than probing the list again.'
		);
	}

	/**
	 * A cached "nothing installed" is trusted rather than re-probed.
	 *
	 * @return void
	 */
	public function test_a_cached_negative_is_not_reprobed(): void {
		$cache = new Cache();

		( new Detector( $cache, array( new FakeInvoiceProvider( 'fixture-a', false ) ) ) )->detect();

		$again = new FakeInvoiceProvider( 'fixture-a', false );

		( new Detector( $cache, array( $again ) ) )->detect();

		$this->assertSame( 0, $again->active_calls );
	}

	/**
	 * Calling forget() drops the cached result, so activating an invoice plugin
	 * does not wait out the TTL.
	 *
	 * @return void
	 */
	public function test_forget_reprobes(): void {
		$cache    = new Cache();
		$provider = new FakeInvoiceProvider( 'fixture-a', false );
		$detector = new Detector( $cache, array( $provider ) );

		$detector->detect();
		$provider->active = true;
		$detector->forget();

		$this->assertNotNull( $detector->detect() );
	}

	/**
	 * A cached id whose plugin has since gone is discarded, not trusted.
	 *
	 * @return void
	 */
	public function test_a_stale_cached_provider_is_discarded(): void {
		$cache = new Cache();

		( new Detector( $cache, array( new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/a.pdf' ) ) ) )->detect();

		$gone = new Detector( $cache, array( new FakeInvoiceProvider( 'fixture-a', false ) ) );

		$this->assertNull( $gone->detect() );
	}

	/**
	 * The `wpmphub_invoice_source` filter can supply a source for a plugin no adapter covers.
	 *
	 * @return void
	 */
	public function test_the_filter_can_supply_a_source(): void {
		FakeWordPress::$filters['wpmphub_invoice_source'][] = static function () {
			return new InvoiceSource( InvoiceSource::KIND_DOCUMENT, 'https://shop.test/filtered.pdf', 'someone-else' );
		};

		$source = $this->detector( new FakeInvoiceProvider( 'fixture-a', false ) )->source_for( $this->order() );

		$this->assertNotNull( $source );
		$this->assertSame( 'https://shop.test/filtered.pdf', $source->url );
	}

	/**
	 * The same filter can remove a detected source.
	 *
	 * @return void
	 */
	public function test_the_filter_can_remove_a_source(): void {
		FakeWordPress::$filters['wpmphub_invoice_source'][] = static function () {
			return null;
		};

		$detector = $this->detector( new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/a.pdf' ) );

		$this->assertNull( $detector->source_for( $this->order() ) );
	}

	/**
	 * A print-view source is never labelled an invoice.
	 *
	 * @return void
	 */
	public function test_a_print_view_is_not_called_an_invoice(): void {
		$document = new InvoiceSource( InvoiceSource::KIND_DOCUMENT, 'https://shop.test/a.pdf', 'fixture-a' );
		$print    = new InvoiceSource( InvoiceSource::KIND_PRINT_VIEW, 'https://shop.test/view-order/4001' );

		$this->assertStringContainsStringIgnoringCase( 'invoice', $document->label() );
		$this->assertStringNotContainsStringIgnoringCase( 'invoice', $print->label() );
		$this->assertFalse( $print->is_document() );
	}
}
