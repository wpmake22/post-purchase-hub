<?php
/**
 * Setup wizard unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\Wizard;
use PostPurchaseHub\Admin\WizardPreview;
use PostPurchaseHub\Admin\WizardScreen;
use PostPurchaseHub\Admin\WizardSteps;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\FakeWpdb;
use PostPurchaseHub\Tests\Unit\Support\WPDieException;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M14's acceptance criteria on the wizard: it is resumable after abandonment
 * mid-step, it changes nothing about the store until it finishes, and finishing
 * is the moment the storefront comes up.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Admin\Wizard
 * @covers \PostPurchaseHub\Admin\WizardScreen
 * @covers \PostPurchaseHub\Admin\WizardSteps
 */
final class WizardTest extends TestCase {

	/**
	 * Wizard under test.
	 *
	 * @var Wizard
	 */
	private Wizard $wizard;

	/**
	 * Builds the wizard over the real screens, panel and stage map.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_capabilities = array( 'manage_woocommerce' );
		FakeWordPress::$current_user_id           = 3;

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		// The health panel reports whether the requests table is there, which
		// step 4 reads for its conflict warning.
		$GLOBALS['wpdb'] = new FakeWpdb( array( Schema::REQUESTS ) );

		$cache  = new Cache();
		$stages = new StageMap( new StatusDetector( $cache ) );
		$logger = new Logger();

		$renderer = new Renderer(
			new TimelineBuilder( $stages, new TransitionRecorder( $stages, $logger ) ),
			new TemplateLoader( $logger ),
			new EstimatedDelivery( new NullTrackingAvailability(), $logger ),
			new PendingCancellationBranch( new RequestRepository() )
		);

		$health = new HealthPanel( new TemplateConflictScanner( $cache ), new Detector( $cache, array() ) );

		$this->wizard = new Wizard(
			$stages,
			$health,
			new WizardScreen( new WizardSteps( new WizardPreview( $renderer ) ) )
		);
	}

	/**
	 * Clears superglobals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tearDown();
	}

	/**
	 * Submits one step.
	 *
	 * @param int                  $step  Step being submitted.
	 * @param array<string, mixed> $value Posted settings values.
	 * @param bool                 $skip  Whether the merchant skipped.
	 * @return void
	 */
	private function submit( int $step, array $value = array(), bool $skip = false ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- This test *is* the form submission being simulated.
		$_POST = array(
			Wizard::STEP_FIELD     => (string) $step,
			SettingsFields::OPTION => $value,
			'_wpnonce'             => 'test-nonce',
		);

		if ( $skip ) {
			$_POST[ Wizard::SKIP_FIELD ] = '1';
		}

		$_REQUEST = $_POST;

		$this->wizard->handle_step();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A step's answers are kept as drafts and the merchant is moved on.
	 *
	 * @return void
	 */
	public function test_a_step_is_saved_as_a_draft_and_advances(): void {
		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '3' ) );

		$this->assertSame( 3, SetupState::draft()[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( 3, SetupState::current_step() );
		$this->assertCount( 1, FakeWordPress::$redirects );
		$this->assertStringContainsString( 'step=3', FakeWordPress::$redirects[0]['location'] );
	}

	/**
	 * Mid-wizard, nothing about the store has changed yet: the settings option
	 * is untouched and the storefront is still dark.
	 *
	 * @return void
	 */
	public function test_nothing_is_written_until_the_wizard_finishes(): void {
		$this->submit( 1, array( StageMapConfig::MAP_SETTING => array( 'processing' => 'in_progress' ) ) );
		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '4' ) );

		$this->assertArrayNotHasKey( SettingsFields::OPTION, FakeWordPress::$options );
		$this->assertFalse( SetupState::is_complete() );
	}

	/**
	 * Abandoning mid-step and coming back reopens the step that was reached,
	 * with the earlier answers intact.
	 *
	 * @return void
	 */
	public function test_it_resumes_after_abandonment(): void {
		$this->submit( 1, array( StageMapConfig::MAP_SETTING => array( 'processing' => 'in_progress' ) ) );
		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '4' ) );

		// A new request, as if the merchant closed the tab and came back.
		$_GET = array();

		$this->assertSame( 3, Wizard::requested_step() );
		$this->assertSame(
			array( 'processing' => 'in_progress' ),
			SetupState::draft()[ StageMapConfig::MAP_SETTING ]
		);
		$this->assertSame( 4, SetupState::draft()[ EstimatedDelivery::HANDLING_SETTING ] );
	}

	/**
	 * A skipped step keeps the merchant moving and records nothing for it.
	 *
	 * @return void
	 */
	public function test_a_skipped_step_records_nothing(): void {
		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '9' ), true );

		$this->assertSame( array(), SetupState::draft() );
		$this->assertSame( 3, SetupState::current_step() );
	}

	/**
	 * Finishing writes every draft to the real settings and opens the storefront.
	 *
	 * @return void
	 */
	public function test_finishing_writes_the_settings_and_goes_live(): void {
		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '4' ) );
		$this->submit( 4, array( TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT ) );
		$this->submit(
			SetupState::FINAL_STEP,
			array( ActionAvailability::SETTING => array( 'cancel' => '1' ) )
		);

		$settings = FakeWordPress::$options[ SettingsFields::OPTION ];

		$this->assertTrue( SetupState::is_complete(), 'Finishing the wizard is what brings the storefront up.' );
		$this->assertSame( 4, $settings[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( TemplateReplacer::MODE_REPLACEMENT, $settings[ TemplateReplacer::SETTING ] );
		$this->assertTrue( $settings[ ActionAvailability::SETTING ]['cancel'] );
		$this->assertFalse( $settings[ ActionAvailability::SETTING ]['reorder'], 'An action left unchecked on the final screen is off.' );
	}

	/**
	 * The settings option is not autoloaded, even written by the wizard.
	 *
	 * @return void
	 */
	public function test_the_settings_option_is_not_autoloaded(): void {
		$this->submit( SetupState::FINAL_STEP );

		$this->assertArrayHasKey( SettingsFields::OPTION, FakeWordPress::$non_autoloaded_options );
	}

	/**
	 * Guest access is never switched on by the wizard: it needs an
	 * acknowledgement the wizard does not ask for.
	 *
	 * @return void
	 */
	public function test_the_wizard_never_enables_guest_access(): void {
		$this->submit( SetupState::FINAL_STEP );

		$settings = FakeWordPress::$options[ SettingsFields::OPTION ];

		$this->assertArrayNotHasKey( 'guest_lookup_enabled', $settings );
	}

	/**
	 * Only the fields a step declares are read from its POST, whatever else was
	 * sent with it.
	 *
	 * @return void
	 */
	public function test_a_step_reads_only_its_own_fields(): void {
		$this->submit(
			2,
			array(
				EstimatedDelivery::HANDLING_SETTING => '4',
				'delete_data_on_uninstall'          => '1',
				'guest_lookup_enabled'              => '1',
			)
		);

		$draft = SetupState::draft();

		$this->assertArrayHasKey( EstimatedDelivery::HANDLING_SETTING, $draft );
		$this->assertArrayNotHasKey( 'delete_data_on_uninstall', $draft );
		$this->assertArrayNotHasKey( 'guest_lookup_enabled', $draft );
	}

	/**
	 * A user without the capability cannot advance the wizard.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_user_without_the_capability(): void {
		FakeWordPress::$current_user_capabilities = array( 'read' );

		$this->expectException( WPDieException::class );

		$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '4' ) );
	}

	/**
	 * A submission with a bad nonce is refused before anything is written.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_bad_nonce(): void {
		FakeWordPress::$valid_referers = array( 'the-only-good-nonce' );

		try {
			$this->submit( 2, array( EstimatedDelivery::HANDLING_SETTING => '4' ) );

			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( WPDieException $e ) {
			unset( $e );

			$this->assertSame( array(), SetupState::draft() );
			$this->assertSame( array(), FakeWordPress::$redirects );
		}
	}

	/**
	 * The wizard page is registered without a permanent menu entry of its own.
	 *
	 * @return void
	 */
	public function test_the_page_is_hidden_from_the_menu(): void {
		$this->wizard->add_page();

		$this->assertCount( 1, FakeWordPress::$submenus );
		$this->assertSame( '', FakeWordPress::$submenus[0]['parent_slug'] );
		$this->assertSame( Wizard::PAGE, FakeWordPress::$submenus[0]['menu_slug'] );
		$this->assertSame( 'manage_woocommerce', FakeWordPress::$submenus[0]['capability'] );
	}

	/**
	 * Every step renders, escaped, with the form and the ways out.
	 *
	 * @return void
	 */
	public function test_every_step_renders(): void {
		for ( $step = SetupState::FIRST_STEP; $step <= SetupState::FINAL_STEP; $step++ ) {
			$_GET = array( 'step' => (string) $step );

			ob_start();
			$this->wizard->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-pph-wizard-step="' . $step . '"', $html );
			$this->assertStringContainsString( 'data-pph-wizard-form', $html );
			$this->assertStringContainsString( 'data-pph-wizard-continue', $html );
			$this->assertStringContainsString( 'data-pph-wizard-skip', $html );
			$this->assertStringNotContainsString( '<script>', $html );
		}
	}

	/**
	 * A merchant cannot be sent past the end of the wizard by a crafted URL.
	 *
	 * @return void
	 */
	public function test_the_requested_step_is_clamped(): void {
		$_GET = array( 'step' => '99' );

		$this->assertSame( SetupState::FINAL_STEP, Wizard::requested_step() );
	}
}
