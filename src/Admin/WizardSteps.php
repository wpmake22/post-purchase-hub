<?php
/**
 * The body of each wizard question.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * One method per question, and nothing about the chrome around it.
 *
 * The controls are the settings screen's own — a merchant who comes back later
 * to change an answer finds the same widget in the same shape, rather than a
 * wizard-only variant of it.
 *
 * Every step reads its value from the drafts first and the stored settings
 * second, which is what makes an abandoned wizard resumable: what was typed on
 * step 2 is still in the boxes on return, without any of it having reached the
 * live settings yet.
 *
 * @since 0.14.0
 */
final class WizardSteps {

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param WizardPreview $preview Draws step 4's real-order preview.
	 */
	public function __construct( private WizardPreview $preview ) {}

	/**
	 * Step 1 — the stage map, proposed from the statuses this store uses.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	public function statuses( array $context ): void {
		$detected = isset( $context['detected_statuses'] ) && is_array( $context['detected_statuses'] )
			? $context['detected_statuses']
			: array();

		$this->heading(
			__( 'Which statuses do your customers see?', 'post-purchase-hub' ),
			__( 'Each status becomes a stage on the customer\'s timeline. A status set to "not shown" contributes nothing to it, which is how an internal status stays internal — and a stage with nothing in it is never shown to a customer.', 'post-purchase-hub' )
		);

		if ( array() !== $detected ) {
			printf(
				'<p class="pph-wizard__detected" data-pph-wizard-detected>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of order statuses found on the store's recent orders. */
						__( 'Found on your recent orders: %s.', 'post-purchase-hub' ),
						implode( ', ', array_map( 'strval', $detected ) )
					)
				)
			);
		}

		$this->matrix( $context )->status_map( StageMapConfig::MAP_SETTING, $this->stored_map( $context ) );
	}

	/**
	 * Step 2 — handling time, globally and per shipping method.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	public function handling( array $context ): void {
		$this->heading(
			__( 'How long before an order leaves you?', 'post-purchase-hub' ),
			__( 'Business days between payment and dispatch. This is what turns an order date into "arrives Tuesday to Thursday" — and the non-working days come from your own settings, not from a guess.', 'post-purchase-hub' )
		);

		$renderer = $this->renderer( $context );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( array( EstimatedDelivery::HANDLING_SETTING, EstimatedDelivery::HANDLING_OVERRIDES_SETTING ) as $key ) {
			$field = SettingsFields::get( $key );

			if ( null !== $field ) {
				$renderer->render_row( $key, $field );
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Step 3 — what tracking data this store has, stated honestly.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	public function tracking( array $context ): void {
		$plugin = isset( $context['tracking'] ) ? (string) $context['tracking'] : '';

		$this->heading(
			__( 'Where does tracking come from?', 'post-purchase-hub' ),
			__( 'This plugin never invents tracking data. It shows a delivery estimate until something real exists, and then gets out of the way.', 'post-purchase-hub' )
		);

		if ( '' !== $plugin ) {
			printf(
				'<p class="pph-wizard__detected" data-pph-wizard-tracking="found">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: name of the detected tracking plugin. */
						__( '%s is installed, and its tracking numbers will be read from it rather than duplicated here.', 'post-purchase-hub' ),
						$plugin
					)
				)
			);

			return;
		}

		printf(
			'<p class="pph-wizard__detected" data-pph-wizard-tracking="none">%s</p>',
			esc_html__( 'No tracking plugin found. Your customers will see estimated delivery dates instead, which is the honest answer — though a real tracking number deflects more questions than an estimate does.', 'post-purchase-hub' )
		);

		printf(
			'<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( admin_url( 'plugin-install.php?s=shipment+tracking&tab=search&type=term' ) ),
			esc_html__( 'Browse tracking plugins', 'post-purchase-hub' )
		);
	}

	/**
	 * Step 4 — additive or full replacement, with the real thing to look at.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	public function display( array $context ): void {
		$this->heading(
			__( 'How should this appear on your order pages?', 'post-purchase-hub' ),
			__( 'Additive adds these sections to the pages your theme already draws. Full replacement takes the order page over, which looks different in every theme — and is the single biggest source of support tickets for plugins like this one.', 'post-purchase-hub' )
		);

		$warning = isset( $context['health'] ) && $context['health'] instanceof HealthPanel
			? self::conflict_warning( $context['health'] )
			: '';

		if ( '' !== $warning ) {
			printf(
				'<div class="notice notice-warning inline" data-pph-wizard-conflict><p>%s</p></div>',
				esc_html( $warning )
			);
		}

		$field = SettingsFields::get( TemplateReplacer::SETTING );

		if ( null !== $field ) {
			echo '<table class="form-table" role="presentation"><tbody>';
			$this->renderer( $context )->render_row( TemplateReplacer::SETTING, $field );
			echo '</tbody></table>';
		}

		printf( '<h3>%s</h3>', esc_html__( 'What your customers will see', 'post-purchase-hub' ) );

		$this->preview->render();
	}

	/**
	 * The final screen — which actions to switch on.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return void
	 */
	public function actions( array $context ): void {
		$this->heading(
			__( 'What should customers be able to do?', 'post-purchase-hub' ),
			__( 'You can change any of this later. Cancellation is always a request you approve or decline — this plugin never cancels an order by itself, and never issues a refund.', 'post-purchase-hub' )
		);

		$settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$draft    = isset( $context['draft'] ) && is_array( $context['draft'] ) ? $context['draft'] : array();
		$stored   = $draft[ ActionAvailability::SETTING ] ?? ( $settings[ ActionAvailability::SETTING ] ?? ActionAvailability::all() );

		$this->matrix( $context )->action_toggles(
			ActionAvailability::SETTING,
			is_array( $stored ) ? $stored : ActionAvailability::all()
		);
	}

	/**
	 * The warning shown when a theme or page builder already owns the order page.
	 *
	 * Read from the health panel rather than scanned again here: one detector,
	 * one answer, whichever screen is asking.
	 *
	 * @since 0.14.0
	 *
	 * @param HealthPanel $health Health panel, which already knows.
	 * @return string Empty when there is nothing to warn about.
	 */
	private static function conflict_warning( HealthPanel $health ): string {
		foreach ( $health->rows() as $row ) {
			if ( 'templates' === $row['id'] && HealthPanel::OK !== $row['state'] ) {
				return $row['value'] . ' ' . __( 'Full replacement stays switched off while that is true, so choosing it here changes nothing until the conflict is resolved.', 'post-purchase-hub' );
			}
		}

		return '';
	}

	/**
	 * A screen's heading and its one paragraph of explanation.
	 *
	 * @since 0.14.0
	 *
	 * @param string $title Question being asked.
	 * @param string $help  Why it is being asked.
	 * @return void
	 */
	private function heading( string $title, string $help ): void {
		printf( '<h2 class="pph-wizard__question">%s</h2>', esc_html( $title ) );
		printf( '<p class="pph-wizard__help">%s</p>', esc_html( $help ) );
	}

	/**
	 * The stage map to show: what the merchant already answered, else what the
	 * store is running now.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return array<string, string>
	 */
	private function stored_map( array $context ): array {
		$draft = isset( $context['draft'] ) && is_array( $context['draft'] ) ? $context['draft'] : array();

		if ( isset( $draft[ StageMapConfig::MAP_SETTING ] ) && is_array( $draft[ StageMapConfig::MAP_SETTING ] ) ) {
			return $draft[ StageMapConfig::MAP_SETTING ];
		}

		return StageMapConfig::stored();
	}

	/**
	 * A field renderer over the drafts collected so far.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return SettingsRenderer
	 */
	private function renderer( array $context ): SettingsRenderer {
		$settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$draft    = isset( $context['draft'] ) && is_array( $context['draft'] ) ? $context['draft'] : array();

		return new SettingsRenderer( $this->stages( $context ), array_merge( $settings, $draft ) );
	}

	/**
	 * A matrix renderer for the composite questions.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return SettingsMatrixRenderer
	 */
	private function matrix( array $context ): SettingsMatrixRenderer {
		return new SettingsMatrixRenderer( $this->stages( $context ) );
	}

	/**
	 * The stage map service from the context.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string, mixed> $context Prepared context.
	 * @return StageMap
	 * @throws \UnexpectedValueException When the context was built without one.
	 */
	private function stages( array $context ): StageMap {
		if ( ! isset( $context['stages'] ) || ! $context['stages'] instanceof StageMap ) {
			// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
			throw new \UnexpectedValueException( esc_html( 'The wizard was rendered without a stage map.' ) );
		}

		return $context['stages'];
	}
}
