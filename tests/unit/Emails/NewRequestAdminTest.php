<?php
/**
 * NewRequestAdmin unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\NewRequestAdmin;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Trigger-level coverage: default recipient, subject placeholder resolution,
 * and behaviour when an order no longer resolves.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\NewRequestAdmin
 */
final class NewRequestAdminTest extends TestCase {

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
	 * Defaults to the site admin email when no recipient is configured.
	 *
	 * @return void
	 */
	public function test_defaults_to_the_site_admin_email(): void {
		FakeWordPress::$options['admin_email'] = 'owner@example.test';

		$order   = new \WC_Order( 12 );
		$request = $this->pending_request( 12 );

		( new NewRequestAdmin() )->trigger( $request, $order );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'owner@example.test', FakeWordPress::$sent_emails[0]['to'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), FakeWordPress::$sent_emails[0]['subject'] );
	}

	/**
	 * Sends even when the order the request was raised against no longer
	 * resolves — unlike the customer-facing emails, the merchant still needs
	 * to know a request came in against *some* order id.
	 *
	 * @return void
	 */
	public function test_still_sends_when_the_order_no_longer_resolves(): void {
		FakeWordPress::$options['admin_email'] = 'owner@example.test';

		$request = $this->pending_request( 999 );

		( new NewRequestAdmin() )->trigger( $request, null );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertStringContainsString( '999', FakeWordPress::$sent_emails[0]['subject'] );
	}

	/**
	 * A pending cancellation request fixture.
	 *
	 * @param int $order_id Order id.
	 * @return Request
	 */
	private function pending_request( int $order_id ): Request {
		return Request::from_row(
			array(
				'id'                  => 5,
				'order_id'            => $order_id,
				'type'                => Request::TYPE_CANCELLATION,
				'customer_email_hash' => str_repeat( 'a', 64 ),
				'source'              => Request::SOURCE_ACCOUNT,
			)
		);
	}
}
