<?php
/**
 * The merchant's stage map, applied through the timeline's own filter.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

/**
 * Puts what the wizard's first step collected into effect.
 *
 * Deliberately not a change to `StageMap`. That class already publishes
 * `wpmphub_status_stage_map` as the way to say which status lands on which stage,
 * and a merchant's saved answer is exactly that statement — so the settings
 * layer uses the documented extension point rather than growing a second,
 * privileged source of truth inside the timeline. The map stays one thing with
 * one reader.
 *
 * Priority 10 is chosen so a developer's own filter at a later priority still
 * wins: stored configuration is a better default than the shipped default, and
 * still only a default.
 *
 * A stored map is merged over the shipped one rather than replacing it, so a
 * status the merchant never saw — one a plugin registers later — keeps its
 * sensible mapping instead of vanishing from every timeline.
 *
 * @since 0.14.0
 */
final class StageMapConfig {

	/**
	 * Settings key holding the status-to-stage map.
	 *
	 * @var string
	 */
	public const MAP_SETTING = 'timeline_status_map';

	/**
	 * The value meaning "show this status to nobody".
	 *
	 * An unmapped status contributes nothing to a customer's timeline, which is
	 * how an internal status (`wc-awaiting-parts`, and every store has one)
	 * stays internal. Stored explicitly so "hidden" survives a merge with the
	 * defaults instead of being read as "not answered".
	 *
	 * @var string
	 */
	public const HIDDEN = '';

	/**
	 * Wires the filter.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wpmphub_status_stage_map', array( $this, 'apply_stored_map' ), 10 );
	}

	/**
	 * Merges the stored map over the shipped one.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $map Map as StageMap built it: status slug => stage key.
	 * @return array<string, string>
	 */
	public function apply_stored_map( $map ): array {
		$map    = is_array( $map ) ? $map : array();
		$stored = self::stored();

		foreach ( $stored as $status => $stage ) {
			if ( self::HIDDEN === $stage ) {
				unset( $map[ $status ] );
				continue;
			}

			$map[ $status ] = $stage;
		}

		return $map;
	}

	/**
	 * The stored map, as saved by the wizard or the settings screen.
	 *
	 * Values are not validated against the live stage list here: `StageMap`
	 * cleans the merged result through its own `clean_map()`, which is the one
	 * place that knows which stages exist.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function stored(): array {
		$settings = get_option( 'wpmphub_settings', array() );

		if ( ! is_array( $settings ) || ! isset( $settings[ self::MAP_SETTING ] ) || ! is_array( $settings[ self::MAP_SETTING ] ) ) {
			return array();
		}

		$map = array();

		foreach ( $settings[ self::MAP_SETTING ] as $status => $stage ) {
			if ( ! is_string( $status ) || ! is_string( $stage ) ) {
				continue;
			}

			$map[ $status ] = $stage;
		}

		return $map;
	}
}
