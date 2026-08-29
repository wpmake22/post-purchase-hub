<?php
/**
 * Setup wizard REST unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Admin\WizardPreview;
use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Install\SetupSteps;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Rest\SetupArgs;
use PostPurchaseHub\Rest\SetupContext;
use PostPurchaseHub\Rest\SetupController;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\FakeWpdb;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The promises M14 made about the wizard, re-asserted where they now live.
 *
 * The wizard moved from an `admin-post.php` form to a REST-driven React app, so
 * the same four properties are checked against the controller: it is resumable
 * after abandonment mid-step, it changes nothing about the store until it
 * finishes, finishing is the moment the storefront comes up, and no route on it
 * answers a user without `manage_woocommerce`.
 *
 * The branching added with the React rewrite gets its own assertions: a shorter
 * path must leave the settings it never asked about on their shipped defaults
 * rather than on a half-answer.
 *
 * @since 0.15.0
 *
 * @covers \PostPurchaseHub\Rest\SetupController
 * @covers \PostPurchaseHub\Rest\SetupArgs
 * @covers \PostPurchaseHub\Install\SetupSteps
 */
final class SetupControllerTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var SetupController
	 */
	private SetupController $controller;

	/**
	 * Builds the controller over the real context, panel and stage map.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_capabilities = array( 'manage_woocommerce' );
		FakeWordPress::$current_user_id           = 3;

		// The health panel reports whether the requests table is there, which
		// the display step reads for its conflict warning.
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

		$this->controller = new SetupController(
			new SetupContext( $stages, $health, new WizardPreview( $renderer ) )
		);
	}

	/**
	 * Which args schema guards each route, so a test posts through the same
	 * sanitisation a real request does.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMAS = array(
		'save_welcome'  => 'welcome',
		'save_statuses' => 'statuses',
		'save_delivery' => 'delivery',
		'save_actions'  => 'actions',
		'save_display'  => 'display',
		'skip'          => 'skip',
	);

	/**
	 * Posts to one step, through that route's declared args schema.
	 *
	 * Running the `sanitize_callback`s here rather than handing the controller
	 * clean values is the point: it is what makes these tests exercise the same
	 * path a browser does, including the parts of the shape the sanitiser fills
	 * in — an actions payload naming one action really does arrive with every
	 * other action set to false.
	 *
	 * @param string               $method Controller method to call.
	 * @param array<string, mixed> $params Request parameters, as posted.
	 * @return array<string, mixed> The state the route answered with.
	 */
	private function post( string $method, array $params = array() ): array {
		$schema = isset( self::SCHEMAS[ $method ] )
			? SetupArgs::{ self::SCHEMAS[ $method ] }()
			: array();

		foreach ( $schema as $name => $field ) {
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}

			$this->assertTrue(
				( $field['validate_callback'] )( $params[ $name ] ),
				$name . ' should be a shape the route accepts.'
			);

			$params[ $name ] = ( $field['sanitize_callback'] )( $params[ $name ] );
		}

		$response = $this->controller->{$method}( new \WP_REST_Request( $params ) );

		return (array) $response->get_data();
	}

	/**
	 * A step's answers are kept as drafts and the merchant is moved on.
	 *
	 * @return void
	 */
	public function test_a_step_is_saved_as_a_draft_and_advances(): void {
		$state = $this->post( 'save_delivery', array( 'handling_days' => 3 ) );

		$this->assertSame( 3, SetupState::draft()[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( SetupSteps::TRACKING, $state['current_step'] );
	}

	/**
	 * Mid-wizard, nothing about the store has changed yet: the settings option
	 * is untouched and the storefront is still dark.
	 *
	 * @return void
	 */
	public function test_nothing_is_written_until_the_wizard_finishes(): void {
		$this->post( 'save_welcome', array( 'path' => SetupSteps::PATH_COMPLETE ) );
		$this->post(
			'save_statuses',
			array( 'status_map' => array( 'processing' => 'in_progress' ) )
		);
		$this->post( 'save_delivery', array( 'handling_days' => 4 ) );

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
		$this->post(
			'save_statuses',
			array( 'status_map' => array( 'processing' => 'in_progress' ) )
		);
		$this->post( 'save_delivery', array( 'handling_days' => 4 ) );

		// A new request, as if the merchant closed the tab and came back.
		$state = (array) $this->controller->get_state()->get_data();

		$this->assertSame( SetupSteps::TRACKING, $state['current_step'] );
		$this->assertSame(
			array( 'processing' => 'in_progress' ),
			$state['draft'][ StageMapConfig::MAP_SETTING ]
		);
		$this->assertSame( 4, $state['draft'][ EstimatedDelivery::HANDLING_SETTING ] );
	}

	/**
	 * A skipped step keeps the merchant moving and records nothing for it,
	 * which is how its setting keeps the shipped default.
	 *
	 * @return void
	 */
	public function test_a_skipped_step_records_nothing(): void {
		$state = $this->post( 'skip', array( 'step' => SetupSteps::DELIVERY ) );

		$this->assertSame( array(), SetupState::draft() );
		$this->assertSame( SetupSteps::TRACKING, $state['current_step'] );
	}

	/**
	 * Finishing writes every draft to the real settings and opens the storefront.
	 *
	 * @return void
	 */
	public function test_finishing_writes_the_settings_and_goes_live(): void {
		$this->post( 'save_delivery', array( 'handling_days' => 4 ) );
		$this->post(
			'save_display',
			array( 'template_mode' => TemplateReplacer::MODE_REPLACEMENT )
		);
		$this->post(
			'save_actions',
			array( 'enabled_actions' => array( 'cancel' => true ) )
		);

		$state = (array) $this->controller->finish()->get_data();

		$settings = FakeWordPress::$options[ SettingsFields::OPTION ];

		$this->assertTrue( $state['completed'] );
		$this->assertTrue( SetupState::is_complete(), 'Finishing the wizard is what brings the storefront up.' );
		$this->assertSame( 4, $settings[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( TemplateReplacer::MODE_REPLACEMENT, $settings[ TemplateReplacer::SETTING ] );
		$this->assertTrue( $settings[ ActionAvailability::SETTING ]['cancel'] );
		$this->assertFalse( $settings[ ActionAvailability::SETTING ]['reorder'], 'An action left off on the actions screen is off.' );
	}

	/**
	 * The settings option is not autoloaded, even written by the wizard.
	 *
	 * @return void
	 */
	public function test_the_settings_option_is_not_autoloaded(): void {
		$this->controller->finish();

		$this->assertArrayHasKey( SettingsFields::OPTION, FakeWordPress::$non_autoloaded_options );
	}

	/**
	 * Guest access is never switched on by the wizard: it needs an
	 * acknowledgement the wizard does not ask for (CLAUDE.md hard rule 15).
	 *
	 * @return void
	 */
	public function test_the_wizard_never_enables_guest_access(): void {
		$this->controller->finish();

		$settings = FakeWordPress::$options[ SettingsFields::OPTION ];

		$this->assertArrayNotHasKey( 'guest_lookup_enabled', $settings );
	}

	/**
	 * Only the fields a step declares are recorded from its request, whatever
	 * else was sent with it.
	 *
	 * @return void
	 */
	public function test_a_step_records_only_its_own_fields(): void {
		$this->post(
			'save_delivery',
			array(
				'handling_days'            => 4,
				'template_mode'            => TemplateReplacer::MODE_REPLACEMENT,
				'delete_data_on_uninstall' => true,
			)
		);

		$draft = SetupState::draft();

		$this->assertArrayHasKey( EstimatedDelivery::HANDLING_SETTING, $draft );
		$this->assertArrayNotHasKey( TemplateReplacer::SETTING, $draft );
		$this->assertArrayNotHasKey( 'delete_data_on_uninstall', $draft );
	}

	/**
	 * The welcome answer decides which screens follow, and a shorter path skips
	 * the ones it does not need rather than half-answering them.
	 *
	 * @return void
	 */
	public function test_the_chosen_path_decides_the_remaining_steps(): void {
		$state = $this->post( 'save_welcome', array( 'path' => SetupSteps::PATH_ACTIONS ) );

		$this->assertSame(
			array( SetupSteps::WELCOME, SetupSteps::ACTIONS, SetupSteps::DISPLAY, SetupSteps::FINISH ),
			array_column( $state['steps'], 'id' )
		);

		$this->assertSame( SetupSteps::ACTIONS, $state['current_step'] );
	}

	/**
	 * Finishing a short path leaves the settings it never asked about absent
	 * from the option, so the services behind them keep their own defaults.
	 *
	 * @return void
	 */
	public function test_a_short_path_leaves_unasked_settings_alone(): void {
		$this->post( 'save_welcome', array( 'path' => SetupSteps::PATH_ACTIONS ) );
		$this->post( 'save_actions', array( 'enabled_actions' => array( 'cancel' => true ) ) );
		$this->controller->finish();

		$settings = FakeWordPress::$options[ SettingsFields::OPTION ];

		$this->assertArrayNotHasKey( StageMapConfig::MAP_SETTING, $settings );
		$this->assertArrayNotHasKey( EstimatedDelivery::HANDLING_SETTING, $settings );
		$this->assertTrue( SetupState::is_complete(), 'A short path still finishes setup.' );
	}

	/**
	 * The wizard can be finished entirely by skipping, and still goes live.
	 *
	 * @return void
	 */
	public function test_it_can_be_finished_by_skipping_everything(): void {
		foreach ( SetupSteps::for_path( SetupSteps::DEFAULT_PATH ) as $step ) {
			if ( SetupSteps::FINISH !== $step ) {
				$this->post( 'skip', array( 'step' => $step ) );
			}
		}

		$this->controller->finish();

		$this->assertTrue( SetupState::is_complete() );
		$this->assertSame( array(), SetupState::draft() );
	}

	/**
	 * Every route is gated on the same administrator capability, and refusing
	 * says which side of logged-in the caller is on.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_user_without_the_capability(): void {
		FakeWordPress::$current_user_capabilities = array( 'read' );

		$error = $this->controller->authorise();

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'wpmphub_setup_forbidden', $error->get_error_code() );
	}

	/**
	 * No route on this controller is registered with `__return_true`, and every
	 * POST declares a schema for the fields it accepts (CLAUDE.md hard rule 3).
	 *
	 * @return void
	 */
	public function test_every_route_declares_a_real_permission_callback(): void {
		$this->controller->register_routes();

		$this->assertNotSame( array(), FakeWordPress::$rest_routes );

		foreach ( FakeWordPress::$rest_routes as $route ) {
			$this->assertNotSame( '__return_true', $route['args']['permission_callback'] );
			$this->assertIsArray( $route['args']['args'] );

			foreach ( $route['args']['args'] as $field ) {
				$this->assertArrayHasKey( 'validate_callback', $field );
				$this->assertArrayHasKey( 'sanitize_callback', $field );
			}
		}
	}

	/**
	 * The context payload answers with this store's own facts rather than with
	 * a fixed list, because that is the whole reason it exists.
	 *
	 * @return void
	 */
	public function test_the_context_describes_this_store(): void {
		$context = (array) $this->controller->get_context()->get_data();

		foreach ( array( 'statuses', 'stages', 'actions', 'display_modes', 'path_choices' ) as $key ) {
			$this->assertNotSame( array(), $context[ $key ], $key . ' should not be empty.' );
		}

		$this->assertArrayHasKey( 'plugin', $context['tracking'] );
		$this->assertIsString( $context['preview'] );
		$this->assertStringNotContainsString( '<script', $context['preview'] );
	}
}
