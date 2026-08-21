<?php
/**
 * Mailer unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\AdminDigest;
use PostPurchaseHub\Emails\Mailer;
use PostPurchaseHub\Emails\NewRequestAdmin;
use PostPurchaseHub\Emails\RequestApproved;
use PostPurchaseHub\Emails\RequestDeclined;
use PostPurchaseHub\Emails\RequestReceived;
use PostPurchaseHub\Emails\SecureOrderLink;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers registration: the six emails this plugin ships, and the
 * `pph_registered_emails` extension point docs/EDITIONS.md names for Pro's
 * return-lifecycle emails.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\Mailer
 */
final class MailerTest extends TestCase {

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
	 * Registers all six of this plugin's own emails, keyed by class name,
	 * alongside whatever WooCommerce classes were already present.
	 *
	 * @return void
	 */
	public function test_registers_all_six_emails_keyed_by_class_name(): void {
		$mailer = new Mailer( new RequestRepository(), new TokenService() );

		$classes = $mailer->register_email_classes( array( 'WC_Email_New_Order' => 'placeholder' ) );

		$this->assertArrayHasKey( 'WC_Email_New_Order', $classes, 'Existing entries are preserved, not replaced.' );

		foreach ( array( RequestReceived::class, RequestApproved::class, RequestDeclined::class, NewRequestAdmin::class, SecureOrderLink::class, AdminDigest::class ) as $class ) {
			$this->assertArrayHasKey( $class, $classes );
			$this->assertInstanceOf( $class, $classes[ $class ] );
		}
	}

	/**
	 * The pph_registered_emails filter can add to, or replace, the list Pro
	 * would use for its own return-lifecycle emails.
	 *
	 * @return void
	 */
	public function test_pph_registered_emails_filter_can_add_to_the_list(): void {
		$mailer = new Mailer( new RequestRepository(), new TokenService() );

		add_filter(
			'pph_registered_emails',
			static function ( array $emails ): array {
				$emails['Fake\\Pro\\ReturnApproved'] = new \stdClass();

				return $emails;
			}
		);

		$classes = $mailer->register_email_classes( array() );

		$this->assertArrayHasKey( 'Fake\\Pro\\ReturnApproved', $classes );
		$this->assertArrayHasKey( RequestReceived::class, $classes, 'The filter adds to the defaults rather than only replacing them, as long as it returns them.' );
	}

	/**
	 * The admin digest is a singleton per Mailer, so the instance registered
	 * with WooCommerce is the one the daily cron trigger fires.
	 *
	 * @return void
	 */
	public function test_admin_digest_is_a_singleton_per_mailer(): void {
		$mailer = new Mailer( new RequestRepository(), new TokenService() );

		$this->assertSame( $mailer->admin_digest(), $mailer->admin_digest() );

		$classes = $mailer->register_email_classes( array() );

		$this->assertSame( $mailer->admin_digest(), $classes[ AdminDigest::class ] );
	}
}
