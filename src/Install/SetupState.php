<?php
/**
 * Setup-wizard progress, and the gate that depends on it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

/**
 * Whether this store has finished setup, and where it got to if not.
 *
 * The whole plugin hangs off this one boolean, because of the sentence
 * docs/MILESTONE-PROMPTS.md M14 puts in capitals: nothing renders on the
 * frontend until the wizard completes. "A plugin that silently rewrites the
 * customer order page on activation is a plugin that gets uninstalled" — so an
 * unconfigured install is inert on the storefront, by construction rather than
 * by every render path remembering to ask.
 *
 * State lives in one non-autoloaded option so an abandoned wizard is
 * resumable: the step the merchant reached, and the answers they had given by
 * then, survive them closing the tab. Nothing here is authoritative *settings*
 * data — `wpmphub_settings` is — this is only the progress marker and the drafts
 * behind it.
 *
 * @since 0.14.0
 */
final class SetupState {

	/**
	 * Option holding the wizard's progress and drafts.
	 *
	 * @var string
	 */
	public const OPTION = 'wpmphub_setup_state';

	/**
	 * The step a wizard that has never been opened starts on.
	 *
	 * @var string
	 */
	public const FIRST_STEP = SetupSteps::WELCOME;

	/**
	 * The step that commits the drafts and opens the storefront.
	 *
	 * @var string
	 */
	public const FINAL_STEP = SetupSteps::FINISH;

	/**
	 * Whether the storefront may render anything at all.
	 *
	 * @since 0.14.0
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		$state    = self::state();
		$complete = ! empty( $state['completed_at'] );

		/**
		 * Filters whether setup counts as complete.
		 *
		 * The escape hatch for a store configured without the wizard — a
		 * provisioning script, WP-CLI, or a settings array committed to a site's
		 * own code. Returning true from here opts a store out of the wizard
		 * entirely; nothing about the storefront is gated on anything else.
		 *
		 * @since 0.14.0
		 *
		 * @param bool                 $complete Whether setup has been completed.
		 * @param array<string, mixed> $state    The raw stored state.
		 */
		return (bool) apply_filters( 'wpmphub_setup_complete', $complete, $state );
	}

	/**
	 * The step the wizard should open on.
	 *
	 * Clamped against the chosen path, not merely against the list of steps
	 * that exist: changing the welcome answer can remove the screen a merchant
	 * was last on, and resuming onto a screen this path does not include would
	 * strand them on a step with no way forward.
	 *
	 * @since 0.14.0
	 *
	 * @return string One of the step slugs of the current path.
	 */
	public static function current_step(): string {
		$state = self::state();
		$step  = isset( $state['step'] ) && is_string( $state['step'] ) ? $state['step'] : self::FIRST_STEP;

		return SetupSteps::clamp( self::path(), $step );
	}

	/**
	 * Records that the merchant reached a step, without completing setup.
	 *
	 * @since 0.14.0
	 *
	 * @param string $step Step reached.
	 * @return void
	 */
	public static function remember_step( string $step ): void {
		$state         = self::state();
		$state['step'] = SetupSteps::clamp( self::path(), $step );

		self::save( $state );
	}

	/**
	 * Which walkthrough the merchant chose on the welcome screen.
	 *
	 * @since 0.15.0
	 *
	 * @return string
	 */
	public static function path(): string {
		$state = self::state();
		$path  = isset( $state['path'] ) && is_string( $state['path'] ) ? $state['path'] : SetupSteps::DEFAULT_PATH;

		return SetupSteps::is_path( $path ) ? $path : SetupSteps::DEFAULT_PATH;
	}

	/**
	 * Records the walkthrough the merchant chose.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path Path slug.
	 * @return void
	 */
	public static function remember_path( string $path ): void {
		$state         = self::state();
		$state['path'] = SetupSteps::is_path( $path ) ? $path : SetupSteps::DEFAULT_PATH;

		self::save( $state );
	}

	/**
	 * Stores the answers a step collected, so a resumed wizard shows them again.
	 *
	 * Drafts are what the merchant typed, not what the store runs on: they are
	 * written to `wpmphub_settings` when the wizard completes. Keys not given are
	 * left alone, so one step's draft never erases another's.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $draft Already-sanitised values, keyed by settings key.
	 * @return void
	 */
	public static function remember_draft( array $draft ): void {
		$state = self::state();
		$known = isset( $state['draft'] ) && is_array( $state['draft'] ) ? $state['draft'] : array();

		$state['draft'] = array_merge( $known, $draft );

		self::save( $state );
	}

	/**
	 * The answers collected so far.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, mixed>
	 */
	public static function draft(): array {
		$state = self::state();

		return isset( $state['draft'] ) && is_array( $state['draft'] ) ? $state['draft'] : array();
	}

	/**
	 * Marks setup finished, and stops the wizard offering itself again.
	 *
	 * @since 0.14.0
	 *
	 * @return void
	 */
	public static function complete(): void {
		$state = self::state();

		$state['step']         = SetupSteps::FINISH;
		$state['completed_at'] = gmdate( 'Y-m-d H:i:s' );

		self::save( $state );

		/**
		 * Fires once a store has finished setup.
		 *
		 * The moment this plugin starts rendering on the storefront, and the
		 * hook to hang a cache flush, a welcome email or an onboarding metric
		 * off.
		 *
		 * @since 0.14.0
		 */
		do_action( 'wpmphub_setup_completed' );
	}

	/**
	 * When setup was completed, as a UTC `Y-m-d H:i:s` string.
	 *
	 * @since 0.14.0
	 *
	 * @return string Empty when setup is not complete.
	 */
	public static function completed_at(): string {
		$state = self::state();

		return isset( $state['completed_at'] ) && is_string( $state['completed_at'] ) ? $state['completed_at'] : '';
	}

	/**
	 * Sends the merchant back to the start, keeping their drafts.
	 *
	 * @since 0.14.0
	 *
	 * @return void
	 */
	public static function restart(): void {
		$state = self::state();

		$state['step'] = SetupSteps::WELCOME;
		unset( $state['completed_at'] );

		self::save( $state );
	}

	/**
	 * The raw stored state, defensively typed.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Writes the state, never autoloaded.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $state State to store.
	 * @return void
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
