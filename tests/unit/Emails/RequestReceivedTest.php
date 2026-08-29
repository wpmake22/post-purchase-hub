<?php
/**
 * RequestReceived unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\RequestReceived;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Trigger-level coverage using the WC_Email test double: recipient and
 * subject placeholder resolution, and the enabled gate.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\RequestReceived
 */
final class RequestReceivedTest extends TestCase {

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
	 * A request against a real order sends to the order's own billing email
	 * with the order number substituted into the subject.
	 *
	 * @return void
	 */
	public function test_sends_to_the_orders_billing_email(): void {
		$order = new \WC_Order( 501 );
		$order->set_billing_email( 'customer@example.test' );

		$request = Request::from_row(
			array(
				'id'                  => 9,
				'order_id'            => 501,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);

		( new RequestReceived() )->trigger( $request, $order );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'customer@example.test', FakeWordPress::$sent_emails[0]['to'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), FakeWordPress::$sent_emails[0]['subject'] );
	}

	/**
	 * Nothing sends once a merchant disables the notification — even though
	 * `wpmphub_request_created` still fires.
	 *
	 * @return void
	 */
	public function test_sends_nothing_when_disabled(): void {
		FakeWordPress::$options['woocommerce_wpmphub_request_received_settings'] = array( 'enabled' => 'no' );

		$order   = new \WC_Order( 501 );
		$request = Request::from_row(
			array(
				'id'                  => 9,
				'order_id'            => 501,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);

		( new RequestReceived() )->trigger( $request, $order );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}

	/**
	 * An order that no longer resolves (the fixture invariant `wpmphub_request_created`
	 * always passes when it exists) is a silent no-op, not a fatal.
	 *
	 * @return void
	 */
	public function test_does_nothing_when_the_order_no_longer_resolves(): void {
		$request = Request::from_row(
			array(
				'id'                  => 9,
				'order_id'            => 999,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);

		( new RequestReceived() )->trigger( $request, null );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}
}
