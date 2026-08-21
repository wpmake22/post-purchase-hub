<?php
/**
 * Draws the settings fields that are a matrix of rows rather than one control.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * The five settings that are really a matrix: a row per order status, per
 * weekday, per shipping method, or per registered action.
 *
 * Split from `SettingsRenderer` because they share nothing with a checkbox but
 * the escaping. Each builds its rows from live store data — the statuses this
 * store has, the shipping methods it offers — so a field that would otherwise
 * be an empty table says so in words instead.
 *
 * @since 0.14.0
 */
final class SettingsMatrixRenderer {

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param StageMap $stages Supplies the stage list the status map offers.
	 */
	public function __construct( private StageMap $stages ) {}

	/**
	 * A checkbox per order status this store has.
	 *
	 * @since 0.14.0
	 *
	 * @param string             $key      Settings key.
	 * @param array<int, string> $selected Currently selected slugs.
	 * @return void
	 */
	public function statuses( string $key, array $selected ): void {
		echo '<fieldset class="pph-settings__group">';

		foreach ( SettingsStatusValues::all() as $slug => $label ) {
			printf(
				'<label class="pph-settings__option"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( SettingsRenderer::name( $key ) . '[]' ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * A stage select per order status.
	 *
	 * @since 0.14.0
	 *
	 * @param string                $key    Settings key.
	 * @param array<string, string> $stored Stored map.
	 * @return void
	 */
	public function status_map( string $key, array $stored ): void {
		$stages = $this->stage_choices();
		$live   = $this->stages->status_map();

		echo '<table class="pph-settings__map widefat striped"><tbody>';

		foreach ( SettingsStatusValues::all() as $slug => $label ) {
			$current = $stored[ $slug ] ?? ( $live[ $slug ] ?? StageMapConfig::HIDDEN );

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			printf( '<select name="%1$s">', esc_attr( SettingsRenderer::name( $key ) . '[' . $slug . ']' ) );

			foreach ( $stages as $stage => $stage_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( (string) $stage ),
					selected( (string) $current, (string) $stage, false ),
					esc_html( (string) $stage_label )
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The stages a status can be mapped to, plus "not shown".
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	private function stage_choices(): array {
		$choices = array( StageMapConfig::HIDDEN => __( '— not shown to customers —', 'post-purchase-hub' ) );

		foreach ( $this->stages->stages() as $stage => $label ) {
			$choices[ $stage ] = $label;
		}

		foreach ( $this->stages->branches() as $stage => $label ) {
			$choices[ $stage ] = $label;
		}

		return $choices;
	}

	/**
	 * A checkbox per weekday.
	 *
	 * @since 0.14.0
	 *
	 * @param string          $key      Settings key.
	 * @param array<int, int> $selected Currently selected days.
	 * @return void
	 */
	public function weekdays( string $key, array $selected ): void {
		$selected = array_map( 'intval', $selected );

		echo '<fieldset class="pph-settings__group">';

		foreach ( self::weekday_labels() as $day => $label ) {
			printf(
				'<label class="pph-settings__option"><input type="checkbox" name="%1$s" value="%2$d" %3$s /> %4$s</label>',
				esc_attr( SettingsRenderer::name( $key ) . '[]' ),
				(int) $day,
				checked( in_array( (int) $day, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Weekday names, starting on Sunday to match PHP's own `w` format.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int, string>
	 */
	private static function weekday_labels(): array {
		$labels = array();

		for ( $day = 0; $day <= 6; $day++ ) {
			// 4 January 1970 was a Sunday, so this walks the week in `w` order
			// regardless of the site's "week starts on" setting.
			$labels[ $day ] = (string) wp_date( 'l', ( 3 + $day ) * DAY_IN_SECONDS );
		}

		return $labels;
	}

	/**
	 * A days input per shipping method.
	 *
	 * @since 0.14.0
	 *
	 * @param string             $key    Settings key.
	 * @param array<string, int> $stored Stored map.
	 * @return void
	 */
	public function method_days( string $key, array $stored ): void {
		$methods = SettingsShippingValues::available();

		if ( array() === $methods ) {
			printf( '<p class="description">%s</p>', esc_html__( 'No shipping methods are configured yet.', 'post-purchase-hub' ) );

			return;
		}

		echo '<table class="pph-settings__map widefat striped"><tbody>';

		foreach ( $methods as $method => $label ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="number" class="small-text" name="%2$s" value="%3$s" min="0" max="60" step="1" /></td></tr>',
				esc_html( $label ),
				esc_attr( SettingsRenderer::name( $key ) . '[' . $method . ']' ),
				esc_attr( isset( $stored[ $method ] ) ? (string) (int) $stored[ $method ] : '' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A min/max pair per shipping method.
	 *
	 * @since 0.14.0
	 *
	 * @param string                                     $key    Settings key.
	 * @param array<string, array{min?: int, max?: int}> $stored Stored map.
	 * @return void
	 */
	public function method_ranges( string $key, array $stored ): void {
		$methods = SettingsShippingValues::available();

		if ( array() === $methods ) {
			printf( '<p class="description">%s</p>', esc_html__( 'No shipping methods are configured yet.', 'post-purchase-hub' ) );

			return;
		}

		echo '<table class="pph-settings__map widefat striped"><tbody>';

		foreach ( $methods as $method => $label ) {
			$range = isset( $stored[ $method ] ) && is_array( $stored[ $method ] ) ? $stored[ $method ] : array();

			printf(
				'<tr><th scope="row">%1$s</th><td><input type="number" class="small-text" name="%2$s" value="%3$s" min="0" max="60" step="1" aria-label="%6$s" /> – <input type="number" class="small-text" name="%4$s" value="%5$s" min="0" max="60" step="1" aria-label="%7$s" /></td></tr>',
				esc_html( $label ),
				esc_attr( SettingsRenderer::name( $key ) . '[' . $method . '][min]' ),
				esc_attr( isset( $range['min'] ) ? (string) (int) $range['min'] : '' ),
				esc_attr( SettingsRenderer::name( $key ) . '[' . $method . '][max]' ),
				esc_attr( isset( $range['max'] ) ? (string) (int) $range['max'] : '' ),
				esc_attr__( 'Fastest, in business days', 'post-purchase-hub' ),
				esc_attr__( 'Slowest, in business days', 'post-purchase-hub' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A checkbox per registered action, with what each one does.
	 *
	 * @since 0.14.0
	 *
	 * @param string              $key    Settings key.
	 * @param array<string, bool> $stored Stored switches.
	 * @return void
	 */
	public function action_toggles( string $key, array $stored ): void {
		$descriptions = ActionAvailability::descriptions();

		echo '<fieldset class="pph-settings__group">';

		foreach ( ActionAvailability::labels() as $action_id => $label ) {
			$enabled = ! isset( $stored[ $action_id ] ) || (bool) $stored[ $action_id ];

			printf(
				'<label class="pph-settings__option"><input type="checkbox" name="%1$s" value="1" %2$s /> <strong>%3$s</strong></label><p class="description">%4$s</p>',
				esc_attr( SettingsRenderer::name( $key ) . '[' . $action_id . ']' ),
				checked( $enabled, true, false ),
				esc_html( $label ),
				esc_html( $descriptions[ $action_id ] ?? '' )
			);
		}

		echo '</fieldset>';
	}
}
