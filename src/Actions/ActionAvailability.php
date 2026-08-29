<?php
/**
 * Which self-service actions a store offers.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * The merchant's on/off switch per action, enforced where eligibility is
 * decided rather than where buttons are drawn.
 *
 * `EligibilityResolver` asks this first, before any other dimension, which is
 * the only placement that makes the switch real: every action's `check()` goes
 * through that resolver, and every REST controller re-checks through the same
 * `check()` at the point of execution. So an action a merchant turned off is
 * absent from the order page *and* refused at the route — never merely hidden
 * in the UI, which is the failure mode docs/SPEC.md Phase 8 keeps returning to.
 *
 * An action absent from the stored list is on. That is deliberate: a store
 * upgrading into a build that adds an action gets it, rather than silently not
 * getting a feature it was never asked about. Only an explicit `false` turns
 * something off.
 *
 * @since 0.14.0
 */
final class ActionAvailability {

	/**
	 * Settings key holding the per-action switches.
	 *
	 * @var string
	 */
	public const SETTING = 'enabled_actions';

	/**
	 * The shipped default: every action on, cancellation as request-and-approve
	 * (which is the only cancellation this plugin has — see `Actions\Cancel`).
	 *
	 * @var array<string, bool>
	 */
	public const DEFAULTS = array(
		Cancel::ID  => true,
		Reorder::ID => true,
		Invoice::ID => true,
		Help::ID    => true,
	);

	/**
	 * Whether an action is available on this store.
	 *
	 * @since 0.14.0
	 *
	 * @param string $action_id Action id, as registered.
	 * @return bool
	 */
	public static function is_enabled( string $action_id ): bool {
		$stored  = self::stored();
		$enabled = ! isset( $stored[ $action_id ] ) || (bool) $stored[ $action_id ];

		/**
		 * Filters whether one action is available on this store.
		 *
		 * The switch a merchant sets on the settings screen, and the hook Pro or
		 * a merchant's own code uses to make availability conditional on
		 * something this plugin does not model — a customer role, a season, a
		 * shipping class.
		 *
		 * @since 0.14.0
		 *
		 * @param bool   $enabled   Whether the action is available.
		 * @param string $action_id Action id.
		 */
		return (bool) apply_filters( 'wpmphub_action_enabled', $enabled, $action_id );
	}

	/**
	 * The switches as stored, for the settings screen to render.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		$stored = self::stored();
		$state  = array();

		foreach ( self::DEFAULTS as $action_id => $default ) {
			$state[ $action_id ] = isset( $stored[ $action_id ] ) ? (bool) $stored[ $action_id ] : $default;
		}

		return $state;
	}

	/**
	 * Human labels for the actions, for the settings screen and the wizard.
	 *
	 * Read from each action rather than restated here, so a renamed button
	 * cannot end up with two names.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			Cancel::ID  => Cancel::label(),
			Reorder::ID => Reorder::label(),
			Invoice::ID => Invoice::label(),
			Help::ID    => Help::label(),
		);
	}

	/**
	 * Explanatory copy per action, for the wizard's final screen.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		return array(
			Cancel::ID  => __( 'Customers ask to cancel; you approve or decline from the request queue. Nothing is cancelled automatically and no refund is ever issued by this plugin.', 'wpmake-post-purchase-hub' ),
			Reorder::ID => __( 'Customers buy a past order again, after a screen showing what has changed: what is out of stock, what no longer exists, what costs more.', 'wpmake-post-purchase-hub' ),
			Invoice::ID => __( 'Links to the invoice your invoice plugin already generated, or to the order page to print when there is none. This plugin generates no PDFs.', 'wpmake-post-purchase-hub' ),
			Help::ID    => __( 'A message form that arrives with the order number, status and items already attached, so you can answer without asking what it is about.', 'wpmake-post-purchase-hub' ),
		);
	}

	/**
	 * The stored switches, defensively typed.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$settings = get_option( 'wpmphub_settings', array() );

		if ( ! is_array( $settings ) || ! isset( $settings[ self::SETTING ] ) || ! is_array( $settings[ self::SETTING ] ) ) {
			return array();
		}

		return $settings[ self::SETTING ];
	}
}
