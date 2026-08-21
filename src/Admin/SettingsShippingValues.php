<?php
/**
 * Per-shipping-method settings values.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

/**
 * The two per-method maps `Timeline\EstimatedDelivery` reads, sanitised in the
 * shapes it expects: handling days as `method => int`, transit as
 * `method => {min, max}`.
 *
 * Both are capped. A map keyed by shipping method grows with a store's shipping
 * configuration, and a posted array is attacker-shaped input: without a cap,
 * one request could write an option large enough to matter (CLAUDE.md hard rule
 * 12).
 *
 * @since 0.14.0
 */
final class SettingsShippingValues {

	/**
	 * Most methods either map may hold.
	 *
	 * @var int
	 */
	public const MAX_METHODS = 100;

	/**
	 * The shipping methods a merchant can configure, as id => label.
	 *
	 * Zone instances first, because that is what a real store ships with: a
	 * method configured twice with different rates is two rows here, matching
	 * how WooCommerce stores the id on the order's shipping line.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function available(): array {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->shipping ) ) {
			return array();
		}

		$methods = WC()->shipping()->get_shipping_methods();
		$clean   = array();

		foreach ( is_array( $methods ) ? $methods : array() as $id => $method ) {
			$label = $method instanceof \WC_Shipping_Method && '' !== $method->method_title
				? $method->method_title
				: (string) $id;

			$clean[ (string) $id ] = $label;
		}

		return $clean;
	}

	/**
	 * A posted method-to-days map, blanks meaning "use the default".
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<string, int>
	 */
	public static function sanitize_days( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $method => $days ) {
			$id = self::method_id( $method );

			if ( '' === $id || ! self::is_number( $days ) ) {
				continue;
			}

			$clean[ $id ] = max( 0, (int) $days );

			if ( count( $clean ) >= self::MAX_METHODS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * A posted method-to-transit-range map.
	 *
	 * A half-filled range is dropped rather than half-stored: a minimum with no
	 * maximum is not a range, and an unconfigured method deliberately shows no
	 * estimate at all rather than a guessed one.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return array<string, array{min: int, max: int}>
	 */
	public static function sanitize_ranges( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $method => $range ) {
			$id = self::method_id( $method );

			if ( '' === $id || ! is_array( $range ) ) {
				continue;
			}

			if ( ! self::is_number( $range['min'] ?? '' ) || ! self::is_number( $range['max'] ?? '' ) ) {
				continue;
			}

			$low  = max( 0, (int) $range['min'] );
			$high = max( 0, (int) $range['max'] );

			$clean[ $id ] = array(
				'min' => min( $low, $high ),
				'max' => max( $low, $high ),
			);

			if ( count( $clean ) >= self::MAX_METHODS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Whether a posted value is a number a merchant actually typed.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	private static function is_number( $value ): bool {
		return is_scalar( $value ) && '' !== trim( (string) $value ) && is_numeric( (string) $value );
	}

	/**
	 * A shipping method or instance id, as WooCommerce writes them
	 * (`flat_rate`, `flat_rate:3`).
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $method Posted key.
	 * @return string Empty when it is not a plausible method id.
	 */
	private static function method_id( $method ): string {
		if ( ! is_scalar( $method ) ) {
			return '';
		}

		$candidate = strtolower( trim( (string) $method ) );

		return 1 === preg_match( '/^[a-z0-9_:-]{1,60}$/', $candidate ) ? $candidate : '';
	}
}
