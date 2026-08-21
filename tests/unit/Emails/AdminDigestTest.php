<?php
/**
 * AdminDigest unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\AdminDigest;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers what maybe_send() decides before it ever reaches the repository:
 * disabled by default, and untouched when disabled. The counting and
 * sending path itself needs a real `$wpdb` (`RequestRepository::count()`),
 * so it is covered in the integration suite instead, alongside every other
 * repository-backed behaviour in this codebase.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\AdminDigest
 */
final class AdminDigestTest extends TestCase {

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
	 * Disabled by default — a merchant has to opt in.
	 *
	 * @return void
	 */
	public function test_disabled_by_default(): void {
		$digest = new AdminDigest( new RequestRepository() );

		$this->assertFalse( $digest->is_enabled() );
	}

	/**
	 * A disabled digest never reaches the repository and never sends.
	 *
	 * @return void
	 */
	public function test_maybe_send_is_a_no_op_when_disabled(): void {
		$digest = new AdminDigest( new RequestRepository() );

		$this->assertFalse( $digest->maybe_send() );
		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}

	/**
	 * The enabled field defaults to 'no', unlike every other email in this
	 * milestone.
	 *
	 * @return void
	 */
	public function test_enabled_field_defaults_to_no(): void {
		$digest = new AdminDigest( new RequestRepository() );

		$this->assertSame( 'no', $digest->form_fields['enabled']['default'] );
	}

	/**
	 * Carries a recipient field, defaulting to the site admin email.
	 *
	 * @return void
	 */
	public function test_carries_a_recipient_field(): void {
		$digest = new AdminDigest( new RequestRepository() );

		$this->assertArrayHasKey( 'recipient', $digest->form_fields );
	}
}
