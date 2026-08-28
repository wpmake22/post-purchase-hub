<?php
/**
 * Draws one settings field.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Timeline\StageMap;

/**
 * The markup half of the settings screen, kept away from the routing half.
 *
 * Every field is the same two-column row — what the setting is called on the
 * left, the control and its explanation on the right — so a merchant scanning a
 * card reads a single column of labels rather than a table they have to parse.
 * Booleans are a switch rather than a checkbox for the same reason: on a screen
 * where most settings are on or off, a checkbox next to the word "Enabled"
 * makes the reader do the work of deciding what the current state is.
 *
 * Every value printed here is escaped at the point of output, every time,
 * including values this plugin sanitised on the way in (CLAUDE.md hard rule 5).
 * Field names are all `pph_settings[key]`, so the Settings API hands the whole
 * tab to one sanitise callback.
 *
 * A field declaring `confirm` renders a `data-pph-confirm` attribute carrying
 * the sentence a merchant has to agree to. The admin script turns that into a
 * confirmation on submit; with no JavaScript the setting simply saves, which is
 * why the two settings where that would matter — guest access and
 * delete-on-uninstall — are *also* enforced server-side: guest access needs its
 * acknowledgement checkbox (`SettingsSanitizer::reconcile()`), and deletion is
 * checked again by `Install\Uninstaller` at uninstall time.
 *
 * @since 0.14.0
 */
final class SettingsRenderer {

	/**
	 * The matrix-field renderer, built on first use.
	 *
	 * @var SettingsMatrixRenderer|null
	 */
	private ?SettingsMatrixRenderer $matrix = null;

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param StageMap             $stages   Supplies the stage list the status map offers.
	 * @param array<string, mixed> $settings Stored settings, defaults already merged in.
	 */
	public function __construct( private StageMap $stages, private array $settings ) {}

	/**
	 * Renders one field's row.
	 *
	 * The label and the help text are also what the search box matches on, so
	 * they are carried in a data attribute rather than scraped back out of the
	 * markup: a filter that reads the rendered text would also match the words
	 * inside a select's options, which is not what a merchant typing "holiday"
	 * is asking for.
	 *
	 * @since 0.14.0
	 *
	 * @param string               $key   Settings key.
	 * @param array<string, mixed> $field Field declaration.
	 * @return void
	 */
	public function render_row( string $key, array $field ): void {
		$id    = 'pph-field-' . sanitize_key( $key );
		$label = (string) ( $field['label'] ?? $key );
		$help  = (string) ( $field['help'] ?? '' );
		$type  = (string) ( $field['type'] ?? 'bool' );

		printf(
			'<div class="pph-settings__row pph-settings__row--%1$s" data-pph-settings-field="%2$s" data-pph-settings-terms="%3$s">',
			esc_attr( sanitize_html_class( $type ) ),
			esc_attr( $key ),
			esc_attr( strtolower( $label . ' ' . $help ) )
		);

		printf(
			'<div class="pph-settings__row-label"><label for="%1$s">%2$s</label></div>',
			esc_attr( $id ),
			esc_html( $label )
		);

		echo '<div class="pph-settings__row-field">';

		$this->render_control( $key, $field, $id );

		if ( '' !== $help ) {
			printf( '<p class="pph-settings__help">%s</p>', esc_html( $help ) );
		}

		echo '</div></div>';
	}

	/**
	 * Renders the control for one field's type.
	 *
	 * @since 0.14.0
	 *
	 * @param string               $key   Settings key.
	 * @param array<string, mixed> $field Field declaration.
	 * @param string               $id    Element id.
	 * @return void
	 */
	private function render_control( string $key, array $field, string $id ): void {
		$type  = (string) ( $field['type'] ?? 'bool' );
		$value = $this->settings[ $key ] ?? ( $field['default'] ?? '' );

		switch ( $type ) {
			case 'bool':
				$this->checkbox( $key, $field, $id, (bool) $value );
				return;
			case 'positive_int':
			case 'days':
			case 'hours':
				$this->number( $key, $field, $id, (int) $value );
				return;
			case 'choice':
				$this->choice( $key, $field, $id, (string) $value );
				return;
			case 'statuses':
				$this->matrix()->statuses( $key, is_array( $value ) ? $value : array() );
				return;
			case 'status_map':
				$this->matrix()->status_map( $key, is_array( $value ) ? $value : array() );
				return;
			case 'weekdays':
				$this->matrix()->weekdays( $key, is_array( $value ) ? $value : array() );
				return;
			case 'dates':
				$this->dates( $key, $id, is_array( $value ) ? $value : array() );
				return;
			case 'method_days':
				$this->matrix()->method_days( $key, is_array( $value ) ? $value : array() );
				return;
			case 'method_range':
				$this->matrix()->method_ranges( $key, is_array( $value ) ? $value : array() );
				return;
			case 'action_toggles':
				$this->matrix()->action_toggles( $key, is_array( $value ) ? $value : array() );
				return;
		}
	}

	/**
	 * A checkbox, with its confirmation sentence attached when it has one.
	 *
	 * @since 0.14.0
	 *
	 * @param string               $key     Settings key.
	 * @param array<string, mixed> $field   Field declaration.
	 * @param string               $id      Element id.
	 * @param bool                 $checked Current value.
	 * @return void
	 */
	private function checkbox( string $key, array $field, string $id, bool $checked ): void {
		// Both words travel with the control so the admin script can keep the
		// label honest as the switch is flipped, without a second translation
		// of "On" living in JavaScript.
		$on  = __( 'On', 'wpmake-post-purchase-hub' );
		$off = __( 'Off', 'wpmake-post-purchase-hub' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() is core's own attribute helper and confirm_attribute() returns markup it built with esc_attr(); every other value here is escaped inline.
		printf(
			'<label class="pph-switch"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s%4$s /><span class="pph-switch__track" aria-hidden="true"></span><span class="pph-switch__label" data-pph-switch-states="%5$s">%6$s</span></label>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			checked( $checked, true, false ),
			self::confirm_attribute( $field, true ),
			esc_attr( $on . '|' . $off ),
			esc_html( $checked ? $on : $off )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * A bounded number input.
	 *
	 * @since 0.14.0
	 *
	 * @param string               $key   Settings key.
	 * @param array<string, mixed> $field Field declaration.
	 * @param string               $id    Element id.
	 * @param int                  $value Current value.
	 * @return void
	 */
	private function number( string $key, array $field, string $id, int $value ): void {
		printf(
			'<input type="number" class="pph-settings__number" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" />',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			(int) $value,
			isset( $field['min'] ) ? (int) $field['min'] : 0,
			isset( $field['max'] ) ? (int) $field['max'] : 9999
		);
	}

	/**
	 * A select, with per-choice confirmation where one is declared.
	 *
	 * @since 0.14.0
	 *
	 * @param string               $key      Settings key.
	 * @param array<string, mixed> $field    Field declaration.
	 * @param string               $id       Element id.
	 * @param string               $selected Current value.
	 * @return void
	 */
	private function choice( string $key, array $field, string $id, string $selected ): void {
		$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- confirm_attribute() returns markup it built with esc_attr(); the id and name are escaped inline.
		printf(
			'<select id="%1$s" name="%2$s"%3$s>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			self::confirm_attribute( $field, $field['confirm']['value'] ?? null )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $choices as $choice => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $choice ),
				selected( $selected, (string) $choice, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * The holiday list, as one date per line.
	 *
	 * @since 0.14.0
	 *
	 * @param string             $key   Settings key.
	 * @param string             $id    Element id.
	 * @param array<int, string> $dates Stored dates.
	 * @return void
	 */
	private function dates( string $key, string $id, array $dates ): void {
		printf(
			'<textarea id="%1$s" name="%2$s" rows="5" class="pph-settings__textarea code" placeholder="2026-12-25">%3$s</textarea>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			esc_textarea( implode( "\n", array_map( 'strval', $dates ) ) )
		);
	}

	/**
	 * The renderer for the fields that are a matrix of rows rather than one
	 * control, built on first use.
	 *
	 * @since 0.14.0
	 *
	 * @return SettingsMatrixRenderer
	 */
	private function matrix(): SettingsMatrixRenderer {
		if ( null === $this->matrix ) {
			$this->matrix = new SettingsMatrixRenderer( $this->stages );
		}

		return $this->matrix;
	}

	/**
	 * The `name` attribute for one settings key.
	 *
	 * Shared with SettingsMatrixRenderer: one option, one naming convention.
	 *
	 * @since 0.14.0
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	public static function name( string $key ): string {
		return SettingsFields::OPTION . '[' . $key . ']';
	}

	/**
	 * The confirmation attribute for a field that declares one.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $field   Field declaration.
	 * @param mixed                $trigger Value that should trigger the confirmation.
	 * @return string Already-escaped attribute markup, or an empty string.
	 */
	private static function confirm_attribute( array $field, $trigger ): string {
		if ( ! isset( $field['confirm']['message'] ) || null === $trigger ) {
			return '';
		}

		return sprintf(
			' data-pph-confirm="%1$s" data-pph-confirm-value="%2$s"',
			esc_attr( (string) $field['confirm']['message'] ),
			esc_attr( is_bool( $trigger ) ? '1' : (string) $trigger )
		);
	}
}
