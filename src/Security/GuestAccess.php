<?php
/**
 * The guest-lookup on/off gate.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * Decides whether this store exposes a public order-lookup surface at all.
 *
 * Off until a merchant has both switched it on and acknowledged what it means
 * (CLAUDE.md hard rule 15): a store that installs this plugin must never end up
 * with an unconfigured public endpoint over its order data. Both settings are
 * read here rather than in each surface, so the shortcode, the block and the
 * REST route cannot drift into disagreeing about whether the feature is live.
 *
 * The acknowledgement flag is written by the setup wizard (docs/SPEC.md
 * Milestone 14), which does not exist yet — so on every store built before it
 * lands this returns false, which is the correct default rather than a gap.
 *
 * Deliberately not gating signed-token access: a token link is mailed only to
 * the address already stored on the order, so it is not a public endpoint and
 * has nothing to do with the risk this gate exists for. docs/SPEC.md Phase 8
 * scopes the default-off rule to "guest lookup" for the same reason, and
 * gating tokens here would mean the secure-link emails Milestone 10 ships send
 * dead links until an unrelated wizard step is completed.
 *
 * @since 0.11.0
 */
final class GuestAccess {

	/**
	 * Settings key for the merchant's on/off choice.
	 *
	 * @var string
	 */
	public const ENABLED_SETTING = 'guest_lookup_enabled';

	/**
	 * Settings key the wizard's acknowledgement step writes.
	 *
	 * @var string
	 */
	public const ACKNOWLEDGED_SETTING = 'guest_lookup_acknowledged';

	/**
	 * Whether the public lookup surface is live on this store.
	 *
	 * @since 0.11.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$settings = get_option( 'wpmphub_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$enabled = ! empty( $settings[ self::ENABLED_SETTING ] ) && ! empty( $settings[ self::ACKNOWLEDGED_SETTING ] );

		if ( ! $enabled ) {
			return false;
		}

		/**
		 * Filters whether the public guest-lookup surface is live.
		 *
		 * A kill switch only. It is applied after the stored settings and can
		 * turn the surface off — for a staging clone, a maintenance window, or
		 * a merchant whose support policy changed — but it cannot turn it on:
		 * the acknowledgement step stays mandatory, because a filter in a
		 * theme's functions.php is not somebody having read what enabling a
		 * public order endpoint means.
		 *
		 * @since 0.11.0
		 *
		 * @param bool $enabled Whether the surface is live per the stored settings.
		 */
		return (bool) apply_filters( 'wpmphub_guest_lookup_enabled', $enabled );
	}
}
