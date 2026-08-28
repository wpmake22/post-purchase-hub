<?php
/**
 * Everything the wizard's React app needs that only PHP knows.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\SettingsShippingValues;
use PostPurchaseHub\Admin\SettingsStatusValues;
use PostPurchaseHub\Admin\WizardPreview;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * One GET, one payload, and no second round trip per screen.
 *
 * The wizard's questions are about this store specifically — the statuses it
 * actually uses, the shipping methods it actually offers, whether it actually
 * has a tracking plugin — and every one of those answers lives in PHP. Sending
 * them all with the first request rather than a fetch per step is what lets a
 * merchant click through the wizard without a spinner between screens, and it
 * keeps the React app free of any knowledge about *where* a status list comes
 * from.
 *
 * Choice lists arrive as `{ value, label }` pairs rather than as maps, because
 * order matters in a select and PHP's associative arrays do not survive JSON
 * with their order guaranteed once the keys look numeric — `0` and `6` for
 * weekdays being exactly that case.
 *
 * @since 0.15.0
 */
final class SetupContext {

	/**
	 * Constructor.
	 *
	 * @since 0.15.0
	 *
	 * @param StageMap      $stages  Stage list and the shipped status map.
	 * @param HealthPanel   $health  The one detector for template conflicts and tracking.
	 * @param WizardPreview $preview Renders the storefront section as it will really look.
	 */
	public function __construct(
		private StageMap $stages,
		private HealthPanel $health,
		private WizardPreview $preview
	) {}

	/**
	 * The whole payload.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return array(
			'statuses'          => self::pairs( SettingsStatusValues::all() ),
			'detected_statuses' => array_values( array_map( 'strval', $this->stages->detect_used_statuses() ) ),
			'stages'            => self::pairs( $this->stage_choices() ),
			'shipping_methods'  => self::pairs( SettingsShippingValues::available() ),
			'handling'          => $this->handling_bounds(),
			'actions'           => $this->action_choices(),
			'display_modes'     => self::pairs( $this->display_choices() ),
			'tracking'          => $this->tracking(),
			'conflict'          => $this->conflict_warning(),
			'preview'           => $this->preview_markup(),
		);
	}

	/**
	 * The stages a status can be mapped to, "not shown" first.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, string>
	 */
	private function stage_choices(): array {
		$choices = array( StageMapConfig::HIDDEN => __( '— not shown to customers —', 'wpmake-post-purchase-hub' ) );

		foreach ( $this->stages->stages() as $stage => $label ) {
			$choices[ (string) $stage ] = (string) $label;
		}

		foreach ( $this->stages->branches() as $stage => $label ) {
			$choices[ (string) $stage ] = (string) $label;
		}

		return $choices;
	}

	/**
	 * The stage this store currently puts each status on, before the merchant
	 * changes anything.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, string>
	 */
	public function live_status_map(): array {
		return $this->stages->status_map();
	}

	/**
	 * The handling-time field's bounds, read from its declaration rather than
	 * restated, so the number input cannot disagree with the sanitiser.
	 *
	 * @since 0.15.0
	 *
	 * @return array{min: int, max: int, default: int}
	 */
	private function handling_bounds(): array {
		$field = SettingsFields::get( EstimatedDelivery::HANDLING_SETTING );
		$field = is_array( $field ) ? $field : array();

		return array(
			'min'     => isset( $field['min'] ) ? (int) $field['min'] : 0,
			'max'     => isset( $field['max'] ) ? (int) $field['max'] : 60,
			'default' => isset( $field['default'] ) ? (int) $field['default'] : EstimatedDelivery::DEFAULT_HANDLING_DAYS,
		);
	}

	/**
	 * Each action with the sentence that explains what turning it on means.
	 *
	 * @since 0.15.0
	 *
	 * @return array<int, array{id: string, label: string, description: string}>
	 */
	private function action_choices(): array {
		$descriptions = ActionAvailability::descriptions();
		$choices      = array();

		foreach ( ActionAvailability::labels() as $id => $label ) {
			$choices[] = array(
				'id'          => (string) $id,
				'label'       => (string) $label,
				'description' => (string) ( $descriptions[ $id ] ?? '' ),
			);
		}

		return $choices;
	}

	/**
	 * Additive or full replacement, worded as the settings screen words them.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, string>
	 */
	private function display_choices(): array {
		$field = SettingsFields::get( TemplateReplacer::SETTING );

		if ( is_array( $field ) && isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			return array_map( 'strval', $field['choices'] );
		}

		return array();
	}

	/**
	 * What this store's tracking situation actually is, stated either way.
	 *
	 * @since 0.15.0
	 *
	 * @return array{plugin: string, message: string, search_url: string}
	 */
	private function tracking(): array {
		$plugin = HealthPanel::detected_tracking_plugin();

		$message = '' !== $plugin
			? sprintf(
				/* translators: %s: name of the detected tracking plugin. */
				__( '%s is installed, and its tracking numbers will be read from it rather than duplicated here.', 'wpmake-post-purchase-hub' ),
				$plugin
			)
			: __( 'No tracking plugin found. Your customers will see estimated delivery dates instead, which is the honest answer — though a real tracking number deflects more questions than an estimate does.', 'wpmake-post-purchase-hub' );

		return array(
			'plugin'     => $plugin,
			'message'    => $message,
			'search_url' => admin_url( 'plugin-install.php?s=shipment+tracking&tab=search&type=term' ),
		);
	}

	/**
	 * The warning shown when a theme or page builder already owns the order
	 * page, read from the health panel rather than scanned again here.
	 *
	 * @since 0.15.0
	 *
	 * @return string Empty when there is nothing to warn about.
	 */
	private function conflict_warning(): string {
		foreach ( $this->health->rows() as $row ) {
			if ( 'templates' === $row['id'] && HealthPanel::OK !== $row['state'] ) {
				return $row['value'] . ' ' . __( 'Full replacement stays switched off while that is true, so choosing it here changes nothing until the conflict is resolved.', 'wpmake-post-purchase-hub' );
			}
		}

		return '';
	}

	/**
	 * The storefront section as it will really look, as markup.
	 *
	 * Rendered by the storefront's own renderer — a preview that draws its own
	 * markup is a preview that can be wrong — and then run through
	 * `wp_kses_post()` on the way out. The renderer escapes at output already;
	 * this second pass exists because the string crosses into React, where it
	 * is set as HTML and so leaves the one place escaping is normally proved.
	 *
	 * @since 0.15.0
	 *
	 * @return string
	 */
	private function preview_markup(): string {
		ob_start();

		$this->preview->render();

		return wp_kses_post( (string) ob_get_clean() );
	}

	/**
	 * A `slug => label` map as an ordered list of value/label pairs.
	 *
	 * @since 0.15.0
	 *
	 * @param array<string, string> $map Map to convert.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function pairs( array $map ): array {
		$pairs = array();

		foreach ( $map as $value => $label ) {
			$pairs[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}

		return $pairs;
	}
}
