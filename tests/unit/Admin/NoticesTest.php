<?php
/**
 * Activation notice unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Admin\Notices;
use PostPurchaseHub\Admin\WizardPage;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\WPDieException;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The one notice this plugin shows: on the right screens, to the right user,
 * until setup is done or they say no.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Admin\Notices
 */
final class NoticesTest extends TestCase {

	/**
	 * Notice under test.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Puts the request on the plugins screen, as a shop configurator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_capabilities = array( 'manage_woocommerce' );
		FakeWordPress::$current_user_id           = 8;
		FakeWordPress::$current_screen            = 'plugins';

		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->notices = new Notices();
	}

	/**
	 * Clears superglobals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		parent::tearDown();
	}

	/**
	 * Renders and returns the markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->notices->maybe_render();

		return (string) ob_get_clean();
	}

	/**
	 * An unconfigured store is told, once, where the wizard is.
	 *
	 * @return void
	 */
	public function test_it_points_at_the_wizard(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-pph-setup-notice', $html );
		$this->assertStringContainsString( WizardPage::PAGE, $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'method="post"', $html, 'Dismissal is a POST: nothing this plugin does mutates on a GET.' );
	}

	/**
	 * A configured store is never nagged.
	 *
	 * @return void
	 */
	public function test_it_stops_once_setup_is_done(): void {
		SetupState::complete();

		$this->assertSame( '', $this->render() );
	}

	/**
	 * A user who cannot configure the store does not see it.
	 *
	 * @return void
	 */
	public function test_it_is_hidden_from_users_who_cannot_act_on_it(): void {
		FakeWordPress::$current_user_capabilities = array( 'edit_shop_orders' );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * It does not appear on unrelated screens.
	 *
	 * @return void
	 */
	public function test_it_stays_off_unrelated_screens(): void {
		FakeWordPress::$current_screen = 'options-permalink';

		$this->assertSame( '', $this->render() );
	}

	/**
	 * It does not appear on the wizard, which does not need telling to be the
	 * wizard.
	 *
	 * @return void
	 */
	public function test_it_stays_off_our_own_screens(): void {
		$_GET = array( 'page' => WizardPage::PAGE );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Dismissing it is remembered for that user, and only that user.
	 *
	 * @return void
	 */
	public function test_dismissal_is_remembered_per_user(): void {
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( Notices::NONCE_ACTION ) );

		$this->notices->dismiss();

		$this->assertTrue( Notices::is_dismissed( 8 ) );
		$this->assertFalse( Notices::is_dismissed( 9 ) );
		$this->assertSame( '', $this->render() );
		$this->assertCount( 1, FakeWordPress::$redirects );
	}

	/**
	 * Dismissal is a nonce-checked round trip, not a link anyone can follow on
	 * someone else's behalf.
	 *
	 * @return void
	 */
	public function test_dismissal_requires_a_nonce(): void {
		$_REQUEST = array( '_wpnonce' => 'forged' );

		try {
			$this->notices->dismiss();

			$this->fail( 'A forged nonce must not dismiss anything.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
			$this->assertFalse( Notices::is_dismissed( 8 ) );
		}
	}

	/**
	 * And it needs the capability, checked before the nonce.
	 *
	 * @return void
	 */
	public function test_dismissal_requires_the_capability(): void {
		FakeWordPress::$current_user_capabilities = array( 'read' );

		try {
			$this->notices->dismiss();

			$this->fail( 'A user without the capability must be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
		}
	}

	/**
	 * The dismissal never leaves wp-admin, whatever redirect was asked for.
	 *
	 * @return void
	 */
	public function test_dismissal_cannot_be_used_as_an_open_redirect(): void {
		$_REQUEST = array(
			'_wpnonce' => wp_create_nonce( Notices::NONCE_ACTION ),
			'redirect' => 'https://evil.test/steal',
		);

		$this->notices->dismiss();

		$this->assertCount( 1, FakeWordPress::$redirects );
		$this->assertStringNotContainsString( 'evil.test', FakeWordPress::$redirects[0]['location'] );
	}
	/**
	 * A GET to the dismissal handler is refused, however it was reached:
	 * `admin_post_{action}` fires for both methods, and this one writes user
	 * meta (CLAUDE.md hard rule 4).
	 *
	 * @return void
	 */
	public function test_dismissal_refuses_a_get(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_REQUEST                  = array( '_wpnonce' => wp_create_nonce( Notices::NONCE_ACTION ) );

		try {
			$this->notices->dismiss();

			$this->fail( 'A GET must not dismiss anything.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 405, $e->status() );
			$this->assertFalse( Notices::is_dismissed( 8 ) );
		}
	}
}
