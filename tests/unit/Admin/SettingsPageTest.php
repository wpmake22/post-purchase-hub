<?php
/**
 * Settings screen unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\SettingsPage;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\FakeWpdb;
use PostPurchaseHub\Tests\Unit\Support\WPDieException;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M14's "capability test on settings save", plus the round trip the same
 * criterion asks for: what a merchant saves is what the reading services see.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Admin\SettingsPage
 * @covers \PostPurchaseHub\Admin\SettingsRenderer
 * @covers \PostPurchaseHub\Admin\SettingsMatrixRenderer
 */
final class SettingsPageTest extends TestCase {

	/**
	 * Page under test.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $page;

	/**
	 * Builds the page over the real stage map and health panel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_capabilities = array( 'manage_woocommerce' );

		$_GET            = array();
		$_POST           = array();
		$GLOBALS['wpdb'] = new FakeWpdb( array( Schema::REQUESTS ) );

		$cache = new Cache();

		$this->page = new SettingsPage(
			new StageMap( new StatusDetector( $cache ) ),
			new HealthPanel( new TemplateConflictScanner( $cache ), new Detector( $cache, array() ) )
		);
	}

	/**
	 * Clears superglobals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();

		parent::tearDown();
	}

	/**
	 * The screen requires WooCommerce's configuration capability, not the
	 * request queue's.
	 *
	 * @return void
	 */
	public function test_it_requires_the_configuration_capability(): void {
		FakeWordPress::$current_user_capabilities = array( 'edit_shop_orders' );

		$this->expectException( WPDieException::class );

		$this->page->render();
	}

	/**
	 * Core's own options handler is told to require the same capability, so a
	 * shop manager cannot save by posting straight to options.php — and cannot
	 * be locked out by core's `manage_options` default either.
	 *
	 * @return void
	 */
	public function test_the_save_handler_requires_the_same_capability(): void {
		$this->page->register_settings();

		$this->assertSame( SettingsPage::CAPABILITY, $this->page->capability() );

		foreach ( SettingsFields::TABS as $tab ) {
			$this->assertArrayHasKey(
				'option_page_capability_' . SettingsPage::GROUP_PREFIX . $tab,
				FakeWordPress::$filters,
				'Every tab group declares its capability.'
			);
		}
	}

	/**
	 * Every tab registers the one option, with a sanitise callback and out of
	 * the REST API.
	 *
	 * @return void
	 */
	public function test_every_tab_registers_the_option_with_a_sanitiser(): void {
		$this->page->register_settings();

		$this->assertCount( count( SettingsFields::TABS ), FakeWordPress::$registered_settings );

		foreach ( FakeWordPress::$registered_settings as $registered ) {
			$this->assertSame( SettingsFields::OPTION, $registered['option'] );
			$this->assertIsArray( $registered['args']['sanitize_callback'] );
			$this->assertFalse( $registered['args']['show_in_rest'], 'Store configuration is not REST-exposed.' );
		}
	}

	/**
	 * A save round-trips: what was posted for one tab is what comes back, with
	 * the other tabs left alone.
	 *
	 * @return void
	 */
	public function test_a_save_round_trips(): void {
		FakeWordPress::$options[ SettingsFields::OPTION ] = array( 'cancel_request_cap' => 9 );

		$_POST = array( SettingsPage::TAB_FIELD => 'timeline' );

		$clean = $this->page->sanitize( array( EstimatedDelivery::HANDLING_SETTING => '6' ) );

		$this->assertSame( 6, $clean[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( 9, $clean['cancel_request_cap'] );
	}

	/**
	 * A forged tab cannot widen what a save may write: an unknown tab falls
	 * back to General, whose fields are the only ones then read.
	 *
	 * @return void
	 */
	public function test_a_forged_tab_cannot_widen_a_save(): void {
		$_POST = array( SettingsPage::TAB_FIELD => 'not-a-tab' );

		$clean = $this->page->sanitize(
			array(
				'delete_data_on_uninstall' => '1',
				'guest_lookup_enabled'     => '1',
			)
		);

		$this->assertArrayNotHasKey( 'delete_data_on_uninstall', $clean );
		$this->assertArrayNotHasKey( 'guest_lookup_enabled', $clean );
	}

	/**
	 * Each tab renders its own fields, escaped, inside a form that posts to
	 * core's options handler.
	 *
	 * @return void
	 */
	public function test_each_tab_renders(): void {
		foreach ( SettingsFields::TABS as $tab ) {
			$_GET = array( 'tab' => $tab );

			ob_start();
			$this->page->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'nav-tab-active', $html, $tab . ' marks itself current.' );
			$this->assertStringNotContainsString( '<script>', $html );

			if ( 'emails' === $tab ) {
				$this->assertStringContainsString( 'tab=email', $html, 'The Emails tab points at WooCommerce\'s own screen.' );
				continue;
			}

			$this->assertStringContainsString( 'options.php', $html, $tab . ' posts to the Settings API.' );
			$this->assertStringContainsString( SettingsPage::TAB_FIELD, $html, $tab . ' names itself in its form.' );
		}
	}

	/**
	 * The General tab carries the health panel, because that is where a
	 * merchant looks first when something is wrong.
	 *
	 * @return void
	 */
	public function test_the_general_tab_shows_the_health_panel(): void {
		$_GET = array( 'tab' => 'general' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-pph-health', $html );
		$this->assertStringContainsString( 'data-pph-health-row="setup"', $html );
	}

	/**
	 * The destructive settings carry their confirmation sentence into the
	 * markup, which is what the admin script acts on.
	 *
	 * @return void
	 */
	public function test_destructive_settings_declare_a_confirmation(): void {
		foreach ( array( 'general', 'guest', 'advanced' ) as $tab ) {
			$_GET = array( 'tab' => $tab );

			ob_start();
			$this->page->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-pph-confirm', $html, $tab . ' asks before its risky setting takes effect.' );
		}
	}

	/**
	 * An unknown tab in the URL shows General rather than an empty screen.
	 *
	 * @return void
	 */
	public function test_an_unknown_tab_falls_back_to_general(): void {
		$_GET = array( 'tab' => 'nope' );

		$this->assertSame( 'general', SettingsPage::current_tab() );
	}

	/**
	 * Defaults are filled in, so an unsaved store and a saved-with-defaults
	 * store render and behave identically.
	 *
	 * @return void
	 */
	public function test_stored_settings_include_every_default(): void {
		$stored = SettingsPage::stored();

		foreach ( array_keys( SettingsFields::all() ) as $key ) {
			$this->assertArrayHasKey( $key, $stored, $key . ' always has a value to show.' );
		}
	}

	/**
	 * Guest access rendered as off stays off: the screen shows what
	 * `Security\GuestAccess` will actually do, not what was ticked.
	 *
	 * @return void
	 */
	public function test_guest_access_cannot_be_saved_without_its_acknowledgement(): void {
		$_POST = array( SettingsPage::TAB_FIELD => 'guest' );

		$clean = $this->page->sanitize( array( GuestAccess::ENABLED_SETTING => '1' ) );

		$this->assertFalse( $clean[ GuestAccess::ENABLED_SETTING ] );
	}
}
