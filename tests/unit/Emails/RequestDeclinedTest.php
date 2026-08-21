<?php
/**
 * RequestDeclined unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\RequestDeclined;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Trigger-level coverage: recipient and subject placeholder resolution.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\RequestDeclined
 */
final class RequestDeclinedTest extends TestCase {

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
	 * Fires to the order's billing email with the order number substituted.
	 *
	 * The `admin_note` non-leak guarantee this class's docblock describes is a
	 * template-rendering concern — asserted in the integration suite, where
	 * `get_content_html()`/`get_content_plain()` actually run.
	 *
	 * @return void
	 */
	public function test_sends_to_the_orders_billing_email(): void {
		$order = new \WC_Order( 42 );
		$order->set_billing_email( 'customer@example.test' );

		$request = Request::from_row(
			array(
				'id'                  => 3,
				'order_id'            => 42,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);

		( new RequestDeclined() )->trigger( $request, $order );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'customer@example.test', FakeWordPress::$sent_emails[0]['to'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), FakeWordPress::$sent_emails[0]['subject'] );
	}

	/**
	 * No order resolving is a silent no-op.
	 *
	 * @return void
	 */
	public function test_does_nothing_when_the_order_no_longer_resolves(): void {
		$request = Request::from_row(
			array(
				'id'                  => 3,
				'order_id'            => 999,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);

		( new RequestDeclined() )->trigger( $request, null );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}
}
