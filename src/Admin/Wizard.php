<?php
/**
 * The four-step setup wizard.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * Four questions, then the storefront goes live — and not before.
 *
 * The wizard exists because of the hard requirement it satisfies: this plugin
 * renders nothing to customers until a merchant has been through it
 * (`Install\SetupState`). So it has to be short enough to finish — four steps,
 * every one skippable, and every answer written to `pph_setup_state` as it is
 * given so a merchant who closes the tab resumes where they left off rather
 * than starting again.
 *
 * Answers are drafts until the last screen. That is what makes abandonment
 * safe: a half-finished wizard has changed nothing about the store, because
 * nothing has reached `pph_settings` yet. `finish()` is the one write, and
 * `SetupState::complete()` immediately after it is the one moment the
 * storefront starts rendering.
 *
 * Each step's own POST goes through `SettingsSanitizer`, the same code path as
 * the settings screen: the wizard is a friendlier way to write the same
 * option, never a second set of rules for writing it.
 *
 * @since 0.14.0
 */
final class Wizard {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE = 'pph-setup';

	/**
	 * Capability required to run setup.
	 *
	 * @var string
	 */
	public const CAPABILITY = SettingsPage::CAPABILITY;

	/**
	 * The admin-post.php action each step submits to.
	 *
	 * @var string
	 */
	public const SAVE_ACTION = 'pph_wizard_save';

	/**
	 * Nonce action for a step submission.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pph_wizard_step';

	/**
	 * Field carrying the step being submitted.
	 *
	 * @var string
	 */
	public const STEP_FIELD = 'pph_step';

	/**
	 * Field set when the merchant skipped a step rather than answering it.
	 *
	 * @var string
	 */
	public const SKIP_FIELD = 'pph_skip';

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param StageMap     $stages  Detected statuses and the stage list for step 1.
	 * @param HealthPanel  $health  Supplies step 3's honest answer about tracking.
	 * @param WizardScreen $screens Draws whichever step is current.
	 */
	public function __construct(
		private StageMap $stages,
		private HealthPanel $health,
		private WizardScreen $screens
	) {}

	/**
	 * Wires the hidden menu page and the step handler.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_step' ) );
	}

	/**
	 * Registers the page without a menu entry of its own.
	 *
	 * The wizard is reached from the activation notice and from the settings
	 * screen, not from a permanent menu item that would still be there years
	 * later offering to set up a configured store.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'',
			__( 'Post-Purchase Hub setup', 'post-purchase-hub' ),
			__( 'Setup', 'post-purchase-hub' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the current step.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'post-purchase-hub' ) );
		}

		$this->screens->render( self::requested_step(), self::context() );
	}

	/**
	 * Handles one step's submission.
	 *
	 * Capability, then nonce, then work — the order `Admin\RequestActionController`
	 * established and for the reason stated there.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function handle_step(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'post-purchase-hub' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$step = self::posted_step();

		if ( ! self::skipped() ) {
			SetupState::remember_draft( $this->sanitize_step( $step ) );
		}

		if ( SetupState::FINAL_STEP === $step ) {
			$this->finish();

			wp_safe_redirect( add_query_arg( 'pph_setup', 'complete', SettingsPage::url() ) );

			return;
		}

		$next = $step + 1;

		SetupState::remember_step( $next );

		wp_safe_redirect( self::url( $next ) );
	}

	/**
	 * Sanitises one step's fields, reusing the settings screen's own rules.
	 *
	 * @since 0.14.0
	 *
	 * @param int $step Step being submitted.
	 * @return array<string, mixed>
	 */
	private function sanitize_step( int $step ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_admin_referer() ran in the caller before anything was read, and every value is sanitised field by field below; the raw array is never used directly.
		$raw = isset( $_POST[ SettingsFields::OPTION ] ) && is_array( $_POST[ SettingsFields::OPTION ] )
			? wp_unslash( $_POST[ SettingsFields::OPTION ] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$draft = array();

		foreach ( self::fields_for_step( $step ) as $key ) {
			$field = SettingsFields::get( $key );

			if ( null === $field ) {
				continue;
			}

			$draft[ $key ] = SettingsSanitizer::sanitize_field( $raw[ $key ] ?? null, $field );
		}

		return $draft;
	}

	/**
	 * Writes every draft to the real settings, then opens the storefront.
	 *
	 * Guest access is deliberately absent from the wizard's steps and so from
	 * its drafts: `Security\GuestAccess` needs an acknowledgement a four-step
	 * wizard should not be collecting in passing, so it stays off until a
	 * merchant turns it on deliberately on the settings screen.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	private function finish(): void {
		$stored = get_option( SettingsFields::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array_merge( $stored, SetupState::draft() );

		update_option( SettingsFields::OPTION, $settings, false );

		SetupState::complete();
	}

	/**
	 * Which settings keys each step collects.
	 *
	 * @since 0.14.0
	 *
	 * @param int $step Step number.
	 * @return array<int, string>
	 */
	public static function fields_for_step( int $step ): array {
		switch ( $step ) {
			case 1:
				return array( StageMapConfig::MAP_SETTING );
			case 2:
				return array(
					\PostPurchaseHub\Timeline\EstimatedDelivery::HANDLING_SETTING,
					\PostPurchaseHub\Timeline\EstimatedDelivery::HANDLING_OVERRIDES_SETTING,
				);
			case 3:
				return array();
			case 4:
				return array( TemplateReplacer::SETTING );
			case SetupState::FINAL_STEP:
				return array( ActionAvailability::SETTING );
			default:
				return array();
		}
	}

	/**
	 * Everything the screens need, prepared once.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return array(
			'stages'            => $this->stages,
			'detected_statuses' => $this->stages->detect_used_statuses(),
			'tracking'          => HealthPanel::detected_tracking_plugin(),
			'health'            => $this->health,
			'draft'             => SetupState::draft(),
			'settings'          => SettingsPage::stored(),
		);
	}

	/**
	 * The step the query string asked for, clamped to one that exists, and
	 * never further ahead than the merchant has reached.
	 *
	 * @since 0.14.0
	 * @return int
	 */
	public static function requested_step(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation between steps; nothing is written on this path.
		$requested = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 0;

		if ( $requested < SetupState::FIRST_STEP ) {
			return SetupState::current_step();
		}

		return min( SetupState::FINAL_STEP, $requested );
	}

	/**
	 * The step a submission came from.
	 *
	 * @since 0.14.0
	 * @return int
	 */
	private static function posted_step(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in the caller.
		$step = isset( $_POST[ self::STEP_FIELD ] ) ? absint( wp_unslash( $_POST[ self::STEP_FIELD ] ) ) : SetupState::FIRST_STEP;

		return min( SetupState::FINAL_STEP, max( SetupState::FIRST_STEP, $step ) );
	}

	/**
	 * Whether the merchant skipped rather than answered.
	 *
	 * @since 0.14.0
	 * @return bool
	 */
	private static function skipped(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in the caller.
		return isset( $_POST[ self::SKIP_FIELD ] );
	}

	/**
	 * The URL of one step.
	 *
	 * @since 0.14.0
	 *
	 * @param int $step Step number.
	 * @return string
	 */
	public static function url( int $step = 0 ): string {
		$args = array( 'page' => self::PAGE );

		if ( $step >= SetupState::FIRST_STEP ) {
			$args['step'] = min( SetupState::FINAL_STEP, $step );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
