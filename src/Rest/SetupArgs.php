<?php
/**
 * Argument schemas for the setup wizard's routes.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\SettingsSanitizer;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\SetupSteps;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * One `args` declaration per wizard route, and one rule about what they may do.
 *
 * Every parameter that ends up in `pph_settings` is sanitised by handing it to
 * `SettingsSanitizer::sanitize_field()` with the same declaration the settings
 * screen renders from. The wizard is a friendlier way to write that option, not
 * a second set of rules for writing it (CLAUDE.md hard rule 3 wants a
 * `sanitize_callback` per field; this makes sure the callback is the one the
 * rest of the plugin already trusts, rather than a REST-shaped copy of it that
 * can drift).
 *
 * `validate_callback` here only checks shape — is this an object, is this one of
 * the slugs we offer. Value-level cleaning belongs to the sanitiser, which
 * already falls back to the field's default rather than rejecting, so a
 * merchant is never shown a 400 for typing 900 into a box that tops out at 60.
 *
 * @since 0.15.0
 */
final class SetupArgs {

	/**
	 * `POST /setup/welcome`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function welcome(): array {
		return array(
			'path' => array(
				'type'              => 'string',
				'required'          => true,
				'enum'              => array_keys( SetupSteps::paths() ),
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && SetupSteps::is_path( $value );
				},
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * `POST /setup/statuses`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function statuses(): array {
		return array(
			'status_map' => self::setting( StageMapConfig::MAP_SETTING, 'object' ),
		);
	}

	/**
	 * `POST /setup/delivery`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function delivery(): array {
		return array(
			'handling_days'      => self::setting( EstimatedDelivery::HANDLING_SETTING, 'integer' ),
			'handling_overrides' => self::setting( EstimatedDelivery::HANDLING_OVERRIDES_SETTING, 'object' ),
		);
	}

	/**
	 * `POST /setup/actions`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function actions(): array {
		return array(
			'enabled_actions' => self::setting( ActionAvailability::SETTING, 'object' ),
		);
	}

	/**
	 * `POST /setup/display`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function display(): array {
		return array(
			'template_mode' => self::setting( TemplateReplacer::SETTING, 'string' ),
		);
	}

	/**
	 * `POST /setup/skip`.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function skip(): array {
		return array(
			'step' => array(
				'type'              => 'string',
				'required'          => true,
				'enum'              => array_keys( SetupSteps::labels() ),
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && SetupSteps::is_step( $value );
				},
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Which settings key each request parameter writes to.
	 *
	 * The REST surface is named for what a merchant is answering; the option is
	 * keyed for what reads it. This is the one place the two names meet, so
	 * neither has to be renamed to match the other.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, string>
	 */
	public static function parameter_map(): array {
		return array(
			'status_map'         => StageMapConfig::MAP_SETTING,
			'handling_days'      => EstimatedDelivery::HANDLING_SETTING,
			'handling_overrides' => EstimatedDelivery::HANDLING_OVERRIDES_SETTING,
			'enabled_actions'    => ActionAvailability::SETTING,
			'template_mode'      => TemplateReplacer::SETTING,
		);
	}

	/**
	 * One parameter backed by a declared setting.
	 *
	 * @since 0.15.0
	 *
	 * @param string $key  Settings key from `SettingsFields`.
	 * @param string $type JSON-schema type this parameter arrives as.
	 * @return array<string, mixed>
	 */
	private static function setting( string $key, string $type ): array {
		return array(
			'type'              => $type,
			'required'          => false,
			'validate_callback' => static function ( $value ) use ( $type ): bool {
				return self::matches_type( $value, $type );
			},
			'sanitize_callback' => static function ( $value ) use ( $key ) {
				$field = SettingsFields::get( $key );

				return null === $field ? null : SettingsSanitizer::sanitize_field( $value, $field );
			},
		);
	}

	/**
	 * Whether a value is the shape its schema promised.
	 *
	 * @since 0.15.0
	 *
	 * @param mixed  $value Value as posted.
	 * @param string $type  Declared JSON-schema type.
	 * @return bool
	 */
	private static function matches_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) || is_object( $value );
			case 'integer':
				return is_numeric( $value );
			default:
				return is_scalar( $value );
		}
	}
}
