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
use PostPurchaseHub\Install\Activator;
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

		// Every test here but the one that removes it needs an install that can
		// mint tokens: this email's whole content is a signed link.
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';
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

	/**
	 * An instance that was never triggered renders nothing instead of fataling.
	 *
	 * WooCommerce constructs every registered email to list on the settings
	 * screen and to render in its email preview, and such an instance has no
	 * order on it. The other five emails only pass `$object` into a template,
	 * which copes with that; this one mints a signed token from the order
	 * first, and doing that to `false` would be a fatal in wp-admin. Found by
	 * raising PHPStan to level 7 at M15.
	 *
	 * @return void
	 */
	public function test_an_untriggered_instance_renders_nothing_rather_than_fataling(): void {
		$email = new SecureOrderLink( new TokenService() );

		$this->assertSame( '', $email->get_content_html() );
		$this->assertSame( '', $email->get_content_plain() );
	}

	/**
	 * An install that cannot mint tokens sends nothing, rather than throwing.
	 *
	 * `TokenService::issue()` throws on a missing secret, which is right — but
	 * this trigger runs on `pph_secure_link_requested`, at `shutdown`, after a
	 * guest lookup. An exception there is a fatal on a request the visitor has
	 * already been answered for, and the email it would have sent has no
	 * content without a link anyway.
	 *
	 * @return void
	 */
	public function test_an_install_without_a_token_secret_sends_nothing(): void {
		unset( FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] );

		$order = new \WC_Order( 91 );
		$order->set_billing_email( 'customer@example.test' );

		( new SecureOrderLink( new TokenService() ) )->trigger( $order );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}
}
