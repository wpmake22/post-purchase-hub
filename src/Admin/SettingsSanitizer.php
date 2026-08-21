<?php
/**
 * Per-type sanitisation for every setting.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Security\GuestAccess;

/**
 * Turns whatever was posted into something the reading services can trust.
 *
 * Sanitisation is by declared `type` rather than by a closure per field, so
 * twenty fields share eight rules and a new field cannot arrive with no rule
 * at all. Three properties hold for every type here, and are what the tests
 * assert field by field:
 *
 * 1. **Nothing unknown survives.** Only keys `SettingsFields` declares for the
 *    tab being saved are read; anything else posted is dropped, which is the
 *    mass-assignment control docs/SPEC.md Phase 8 asks for.
 * 2. **Malformed input never fatals.** A string where an array belongs, an
 *    array where an integer belongs, a nested array, an object — each falls
 *    back to the field's default rather than reaching a service that assumed
 *    otherwise.
 * 3. **Impossible combinations are refused, not stored.** Guest lookup cannot
 *    be saved as enabled without its acknowledgement, whatever the form said.
 *
 * @since 0.14.0
 */
final class SettingsSanitizer {

	/**
	 * Longest a holiday list may get, so one paste cannot grow the option
	 * without bound (CLAUDE.md hard rule 12).
	 *
	 * @var int
	 */
	public const MAX_HOLIDAYS = 200;

	/**
	 * Sanitises one tab's posted values and merges them over what is stored.
	 *
	 * Merging is why a six-tab screen can share one option: a tab posts only
	 * its own fields, and saving the Timeline tab must not blank the Actions
	 * tab.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed                $raw      Posted values for this tab.
	 * @param string               $tab      Tab being saved.
	 * @param array<string, mixed> $existing Currently stored settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_tab( $raw, string $tab, array $existing ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$fields = SettingsFields::for_tab( $tab );
		$clean  = $existing;

		foreach ( $fields as $key => $field ) {
			$clean[ $key ] = self::sanitize_field( $raw[ $key ] ?? null, $field );
		}

		return self::reconcile( $clean, $tab );
	}

	/**
	 * Sanitises one value against its field declaration.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed                $value Posted value, null when the field was absent.
	 * @param array<string, mixed> $field Field declaration.
	 * @return mixed
	 */
	public static function sanitize_field( $value, array $field ) {
		$type    = isset( $field['type'] ) ? (string) $field['type'] : 'bool';
		$default = $field['default'] ?? null;

		switch ( $type ) {
			case 'bool':
				// An absent checkbox is an unchecked checkbox, never "leave as it was".
				return ! empty( $value );
			case 'positive_int':
				return self::integer( $value, $field, 1 );
			case 'days':
			case 'hours':
				return self::integer( $value, $field, 0 );
			case 'choice':
				$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? array_keys( $field['choices'] ) : array();

				return is_scalar( $value ) && in_array( (string) $value, $choices, true ) ? (string) $value : $default;
			case 'statuses':
				return SettingsStatusValues::sanitize_list( $value, is_array( $default ) ? $default : array() );
			case 'status_map':
				return SettingsStatusValues::sanitize_map( $value );
			case 'weekdays':
				return self::weekdays( $value );
			case 'dates':
				return self::dates( $value );
			case 'method_days':
				return SettingsShippingValues::sanitize_days( $value );
			case 'method_range':
				return SettingsShippingValues::sanitize_ranges( $value );
			case 'action_toggles':
				return self::action_toggles( $value );
			default:
				return $default;
		}
	}

	/**
	 * An integer within the field's declared bounds.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed                $value   Posted value.
	 * @param array<string, mixed> $field   Field declaration, for its min/max/default.
	 * @param int                  $floor   Lowest value this type allows.
	 * @return int
	 */
	private static function integer( $value, array $field, int $floor ): int {
		if ( ! is_scalar( $value ) || '' === (string) $value || ! is_numeric( (string) $value ) ) {
			return (int) ( $field['default'] ?? $floor );
		}

		$min = isset( $field['min'] ) ? (int) $field['min'] : $floor;
		$max = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;

		return min( $max, max( $min, (int) $value ) );
	}

	/**
	 * Days of the week, as unique integers 0–6.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<int, int>
	 */
	private static function weekdays( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $day ) {
			if ( ! is_scalar( $day ) || ! is_numeric( (string) $day ) ) {
				continue;
			}

			$day = (int) $day;

			if ( $day >= 0 && $day <= 6 ) {
				$clean[] = $day;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		sort( $clean );

		return $clean;
	}

	/**
	 * Holiday dates, from a textarea or an array, as real `Y-m-d` dates.
	 *
	 * A date that does not exist — the 31st of February, a typo — is dropped
	 * rather than stored for `Support\Dates` to reason about later.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<int, string>
	 */
	private static function dates( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$date = trim( (string) $candidate );

			if ( '' === $date || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

			if ( $parsed instanceof \DateTimeImmutable && $parsed->format( 'Y-m-d' ) === $date ) {
				$clean[] = $date;
			}

			if ( count( $clean ) >= self::MAX_HOLIDAYS ) {
				break;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The per-action switches, restricted to actions this build has.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<string, bool>
	 */
	private static function action_toggles( $value ): array {
		$posted = is_array( $value ) ? $value : array();
		$clean  = array();

		foreach ( array_keys( ActionAvailability::DEFAULTS ) as $action_id ) {
			$clean[ $action_id ] = ! empty( $posted[ $action_id ] );
		}

		return $clean;
	}

	/**
	 * Refuses combinations that must not be stored, whatever was posted.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $clean Sanitised settings.
	 * @param string               $tab   Tab being saved.
	 * @return array<string, mixed>
	 */
	private static function reconcile( array $clean, string $tab ): array {
		if ( 'guest' !== $tab ) {
			return $clean;
		}

		// docs/MILESTONE-PROMPTS.md M14: guest access cannot be enabled without
		// the acknowledgement. Security\GuestAccess already requires both flags
		// at read time; this stops the inconsistent state being written at all,
		// so a merchant is never looking at a checked box that does nothing.
		if ( empty( $clean[ GuestAccess::ACKNOWLEDGED_SETTING ] ) ) {
			$clean[ GuestAccess::ENABLED_SETTING ] = false;
		}

		return $clean;
	}
}
