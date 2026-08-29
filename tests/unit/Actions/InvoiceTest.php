<?php
/**
 * Invoice action unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Invoice;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Invoices\InvoiceSource;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Integrations\Invoices\FakeInvoiceProvider;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M13's first acceptance criterion, from both sides: what the customer is
 * offered when an invoice exists, and the absence of anything at all when one
 * does not.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Actions\Invoice
 */
final class InvoiceTest extends TestCase {

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
	 * An order owned by the current user.
	 *
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		$order = new \WC_Order( 7001, 'completed' );
		$order->set_billing_email( 'customer@example.test' );

		FakeWordPress::$orders[7001] = $order;

		return $order;
	}

	/**
	 * The action, over a detector holding one fixture provider.
	 *
	 * @param string|null $url URL the fixture provider reports, null for none.
	 * @return Invoice
	 */
	private function action( ?string $url ): Invoice {
		$provider = new FakeInvoiceProvider( 'fixture-a', null !== $url, $url );

		return new Invoice(
			new EligibilityResolver( new FakeRequestHistory() ),
			new Detector( new Cache(), array( $provider ) )
		);
	}

	/**
	 * A generated invoice is offered in both contexts, labelled as an invoice.
	 *
	 * @return void
	 */
	public function test_a_document_is_offered_in_both_contexts(): void {
		$action = $this->action( 'https://shop.test/invoice/7001.pdf' );
		$order  = $this->order();

		foreach ( array( 'list', 'detail' ) as $context ) {
			$payload = $action->resolve( $order, $context );

			$this->assertNotNull( $payload, 'An existing invoice is offered in the ' . $context . ' context.' );
			$this->assertSame( 'https://shop.test/invoice/7001.pdf', $payload['url'] );
			$this->assertStringContainsStringIgnoringCase( 'invoice', $payload['name'] );
		}
	}

	/**
	 * With no invoice plugin, the order page offers nothing: no button, no
	 * broken link, no placeholder.
	 *
	 * @return void
	 */
	public function test_nothing_is_offered_on_the_order_page_without_a_source(): void {
		$this->assertNull( $this->action( null )->resolve( $this->order(), 'detail' ) );
	}

	/**
	 * With no invoice plugin, the orders list still offers the order's own
	 * page — the print-view fallback — and never calls it an invoice.
	 *
	 * @return void
	 */
	public function test_the_orders_list_falls_back_to_the_order_page(): void {
		$order   = $this->order();
		$payload = $this->action( null )->resolve( $order, 'list' );

		$this->assertNotNull( $payload );
		$this->assertSame( $order->get_view_order_url(), $payload['url'] );
		$this->assertStringNotContainsStringIgnoringCase( 'invoice', $payload['name'] );
	}

	/**
	 * A store can switch the fallback off and be left with nothing anywhere
	 * until an invoice plugin provides something.
	 *
	 * @return void
	 */
	public function test_the_fallback_can_be_filtered_off(): void {
		FakeWordPress::$filters['wpmphub_invoice_print_fallback'][] = static function (): bool {
			return false;
		};

		$action = $this->action( null );
		$order  = $this->order();

		$this->assertNull( $action->resolve( $order, 'list' ) );
		$this->assertNull( $action->resolve( $order, 'detail' ) );
	}

	/**
	 * An order with no view URL at all gets no fallback rather than a link to
	 * nowhere.
	 *
	 * @return void
	 */
	public function test_no_fallback_without_a_view_url(): void {
		$order = $this->order();
		$order->set_view_order_url( '' );

		$this->assertNull( $this->action( null )->resolve( $order, 'list' ) );
	}

	/**
	 * `wpmphub_action_eligibility` can deny the action, as it can any other.
	 *
	 * @return void
	 */
	public function test_the_eligibility_filter_can_deny_it(): void {
		FakeWordPress::$filters['wpmphub_action_eligibility'][] = static function ( $result, $action_id ) {
			return Invoice::ID === $action_id
				? \PostPurchaseHub\Actions\EligibilityResult::denied( 'merchant_choice', 'Not available.' )
				: $result;
		};

		$this->assertNull( $this->action( 'https://shop.test/invoice/7001.pdf' )->resolve( $this->order(), 'detail' ) );
	}

	/**
	 * The action registers itself for both contexts, under its own id.
	 *
	 * @return void
	 */
	public function test_it_registers_for_both_contexts(): void {
		$registry = new ActionRegistry();

		$this->action( null )->register( $registry );

		$registered = $registry->get( Invoice::ID );

		$this->assertNotNull( $registered );
		$this->assertTrue( $registered->applies_to( 'list' ) );
		$this->assertTrue( $registered->applies_to( 'detail' ) );
	}

	/**
	 * A source supplied by the filter reaches the order page, which is how an
	 * invoice plugin with no adapter here is integrated.
	 *
	 * @return void
	 */
	public function test_a_filtered_source_reaches_the_detail_page(): void {
		FakeWordPress::$filters['wpmphub_invoice_source'][] = static function () {
			return new InvoiceSource( InvoiceSource::KIND_DOCUMENT, 'https://shop.test/other-plugin.pdf', 'other' );
		};

		$payload = $this->action( null )->resolve( $this->order(), 'detail' );

		$this->assertNotNull( $payload );
		$this->assertSame( 'https://shop.test/other-plugin.pdf', $payload['url'] );
	}
}
