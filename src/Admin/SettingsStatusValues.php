<?php
/**
 * Order-status values, as the settings screen and the wizard both need them.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * The one place "is this a status this store has" is answered.
 *
 * Shared, because three surfaces ask it: the settings screen sanitising a
 * posted list, the wizard proposing a stage map from detected statuses, and the
 * settings screen again when it renders the checkboxes. A second copy of the
 * `wc-` prefix handling is how a store ends up with `wc-processing` stored in
 * one place and `processing` in another, and a timeline that quietly stops
 * matching.
 *
 * @since 0.14.0
 */
final class SettingsStatusValues {

	/**
	 * Every status this store has, as slug => label, without the `wc-` prefix.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$clean    = array();

		foreach ( is_array( $statuses ) ? $statuses : array() as $status => $label ) {
			$slug = self::slug( (string) $status );

			if ( '' !== $slug ) {
				$clean[ $slug ] = is_scalar( $label ) ? (string) $label : $slug;
			}
		}

		return $clean;
	}

	/**
	 * The slugs alone.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * A status slug without WooCommerce's `wc-` prefix.
	 *
	 * @since 0.14.0
	 *
	 * @param string $status Status, prefixed or not.
	 * @return string
	 */
	public static function slug( string $status ): string {
		$slug = sanitize_key( $status );

		return str_starts_with( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
	}

	/**
	 * A posted list of statuses, reduced to the ones this store has.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed              $value   Posted value.
	 * @param array<int, string> $fallback Used when the value is not a list at all.
	 * @return array<int, string>
	 */
	public static function sanitize_list( $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$known = self::slugs();
		$clean = array();

		foreach ( $value as $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$slug = self::slug( (string) $status );

			if ( '' !== $slug && in_array( $slug, $known, true ) ) {
				$clean[] = $slug;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * A posted status-to-stage map, with unknown statuses dropped and
	 * deliberately hidden ones kept as such.
	 *
	 * Stage keys are only shape-checked here. Which stages exist is
	 * `Timeline\StageMap`'s knowledge, and it cleans the merged map itself —
	 * duplicating that list here would give it two owners.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<string, string>
	 */
	public static function sanitize_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$known = self::slugs();
		$clean = array();

		foreach ( $value as $status => $stage ) {
			if ( ! is_scalar( $stage ) ) {
				continue;
			}

			$slug = self::slug( (string) $status );

			if ( '' === $slug || ! in_array( $slug, $known, true ) ) {
				continue;
			}

			$clean[ $slug ] = StageMapConfig::HIDDEN === (string) $stage
				? StageMapConfig::HIDDEN
				: sanitize_key( (string) $stage );
		}

		return $clean;
	}
}
