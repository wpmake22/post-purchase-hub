<?php
/**
 * SecureOrderLink unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\SecureOrderLink;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the one thing that makes this email different from the other five:
 * it is manually triggered and must send even when a merchant has disabled
 * it in settings, because disabling is about the notification type, not
 * about a customer's own explicit "send me a link" action.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\SecureOrderLink
 */
final class SecureOrderLinkTest extends TestCase {

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
	 * Is flagged manual, matching WC_Email_Customer_Invoice's own convention.
	 *
	 * @return void
	 */
	public function test_is_a_manual_email(): void {
		$email = new SecureOrderLink( new TokenService() );

		$this->assertTrue( $email->is_manual() );
		$this->assertTrue( $email->is_customer_email() );
	}

	/**
	 * Sends even when disabled in settings — a manual send bypasses the
	 * enabled gate by design (see the class docblock).
	 *
	 * @return void
	 */
	public function test_sends_even_when_disabled_in_settings(): void {
		FakeWordPress::$options['woocommerce_pph_secure_link_settings'] = array( 'enabled' => 'no' );

		$order = new \WC_Order( 88 );
		$order->set_billing_email( 'customer@example.test' );

		( new SecureOrderLink( new TokenService() ) )->trigger( $order );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'customer@example.test', FakeWordPress::$sent_emails[0]['to'] );
	}

	/**
	 * Nothing sends when the order carries no billing email at all.
	 *
	 * @return void
	 */
	public function test_sends_nothing_without_a_recipient(): void {
		$order = new \WC_Order( 88 );

		( new SecureOrderLink( new TokenService() ) )->trigger( $order );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}
}
