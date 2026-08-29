<?php
/**
 * Setup-state unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Install\SetupSteps;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The gate the whole storefront hangs off, and the resumability that makes it
 * survivable — docs/MILESTONE-PROMPTS.md M14's "wizard resumable after
 * abandonment mid-step".
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Install\SetupState
 */
final class SetupStateTest extends TestCase {

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
	 * A fresh install is not set up, and so renders nothing.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_is_not_complete(): void {
		$this->assertFalse( SetupState::is_complete() );
		$this->assertSame( SetupState::FIRST_STEP, SetupState::current_step() );
		$this->assertSame( '', SetupState::completed_at() );
	}

	/**
	 * Completing setup is what opens the storefront.
	 *
	 * @return void
	 */
	public function test_completing_setup_opens_the_storefront(): void {
		SetupState::complete();

		$this->assertTrue( SetupState::is_complete() );
		$this->assertNotSame( '', SetupState::completed_at() );
	}

	/**
	 * Completion fires the hook a cache flush or an onboarding metric hangs off.
	 *
	 * @return void
	 */
	public function test_completion_fires_its_action(): void {
		$fired = 0;

		FakeWordPress::$actions['wpmphub_setup_completed'][] = array(
			'callback' => static function () use ( &$fired ): void {
				++$fired;
			},
			'priority' => 10,
		);

		SetupState::complete();

		$this->assertSame( 1, $fired );
	}

	/**
	 * A store configured without the wizard can say so, rather than staying
	 * dark forever.
	 *
	 * @return void
	 */
	public function test_the_filter_can_declare_a_store_configured(): void {
		FakeWordPress::$filters['wpmphub_setup_complete'][] = static function (): bool {
			return true;
		};

		$this->assertTrue( SetupState::is_complete() );
	}

	/**
	 * The step is remembered, so an abandoned wizard reopens where it stopped.
	 *
	 * @return void
	 */
	public function test_the_step_is_remembered(): void {
		SetupState::remember_step( SetupSteps::TRACKING );

		$this->assertSame( SetupSteps::TRACKING, SetupState::current_step() );
		$this->assertFalse( SetupState::is_complete(), 'Reaching a step is not finishing setup.' );
	}

	/**
	 * A step slug the wizard does not have is refused rather than trusted.
	 *
	 * @return void
	 */
	public function test_an_unknown_step_falls_back_to_the_first(): void {
		SetupState::remember_step( 'definitely-not-a-step' );

		$this->assertSame( SetupSteps::WELCOME, SetupState::current_step() );
	}

	/**
	 * A step this path does not include cannot be resumed onto: answering the
	 * welcome screen can remove screens further along, and landing on one of
	 * those would strand a merchant on a step with no way forward.
	 *
	 * @return void
	 */
	public function test_a_step_outside_the_chosen_path_is_clamped(): void {
		SetupState::remember_path( SetupSteps::PATH_ACTIONS );
		SetupState::remember_step( SetupSteps::STATUSES );

		$this->assertSame( SetupSteps::WELCOME, SetupState::current_step() );
	}

	/**
	 * The path is remembered, and an unknown one reads as the default rather
	 * than as a wizard with no steps at all.
	 *
	 * @return void
	 */
	public function test_the_path_is_remembered_and_defended(): void {
		$this->assertSame( SetupSteps::DEFAULT_PATH, SetupState::path() );

		SetupState::remember_path( SetupSteps::PATH_TIMELINE );
		$this->assertSame( SetupSteps::PATH_TIMELINE, SetupState::path() );

		SetupState::remember_path( 'not-a-path' );
		$this->assertSame( SetupSteps::DEFAULT_PATH, SetupState::path() );
	}

	/**
	 * Drafts accumulate across steps instead of one step erasing another's.
	 *
	 * @return void
	 */
	public function test_drafts_accumulate(): void {
		SetupState::remember_draft( array( 'eta_handling_days' => 2 ) );
		SetupState::remember_draft( array( 'template_mode' => 'additive' ) );

		$this->assertSame(
			array(
				'eta_handling_days' => 2,
				'template_mode'     => 'additive',
			),
			SetupState::draft()
		);
	}

	/**
	 * A step done again replaces its own earlier answer.
	 *
	 * @return void
	 */
	public function test_a_redone_step_overwrites_its_own_draft(): void {
		SetupState::remember_draft( array( 'eta_handling_days' => 2 ) );
		SetupState::remember_draft( array( 'eta_handling_days' => 5 ) );

		$this->assertSame( array( 'eta_handling_days' => 5 ), SetupState::draft() );
	}

	/**
	 * Drafts are not settings: collecting them changes nothing about the store,
	 * which is what makes abandoning the wizard safe.
	 *
	 * @return void
	 */
	public function test_drafts_do_not_touch_the_live_settings(): void {
		SetupState::remember_draft( array( 'template_mode' => 'replacement' ) );

		$this->assertArrayNotHasKey( 'wpmphub_settings', FakeWordPress::$options );
		$this->assertFalse( SetupState::is_complete() );
	}

	/**
	 * Restarting keeps what was collected but reopens the wizard.
	 *
	 * @return void
	 */
	public function test_restarting_reopens_the_wizard(): void {
		SetupState::remember_draft( array( 'eta_handling_days' => 4 ) );
		SetupState::complete();
		SetupState::restart();

		$this->assertFalse( SetupState::is_complete() );
		$this->assertSame( SetupState::FIRST_STEP, SetupState::current_step() );
		$this->assertSame( array( 'eta_handling_days' => 4 ), SetupState::draft() );
	}

	/**
	 * A corrupted option reads as "not set up" rather than fataling.
	 *
	 * @return void
	 */
	public function test_a_corrupted_option_is_survivable(): void {
		FakeWordPress::$options[ SetupState::OPTION ] = 'not an array';

		$this->assertFalse( SetupState::is_complete() );
		$this->assertSame( SetupState::FIRST_STEP, SetupState::current_step() );
		$this->assertSame( array(), SetupState::draft() );
	}

	/**
	 * The state option is never autoloaded: one gate reads it, not every
	 * request of every site.
	 *
	 * @return void
	 */
	public function test_the_option_is_not_autoloaded(): void {
		SetupState::remember_step( SetupSteps::STATUSES );

		$this->assertArrayHasKey( SetupState::OPTION, FakeWordPress::$non_autoloaded_options );
	}
}
