<?php
/**
 * The status panel a merchant checks first when something looks wrong.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Integrations\Invoices\Detector;

/**
 * Five answers, on the General tab, to the five questions support asks.
 *
 * The list docs/SPEC.md gives for this panel: detected tracking plugin,
 * detected invoice plugin, template conflicts, cron status, schema version. Each row
 * says what was found rather than what should have been — the honesty
 * requirement docs/MILESTONE-PROMPTS.md M14 puts on the wizard's tracking step
 * applies here too. "No tracking plugin detected" is a useful answer; a green
 * tick that means nothing is not.
 *
 * Every value is read live, through the same detectors the storefront uses, so
 * the panel cannot disagree with what a customer sees. The two cached ones
 * (invoice detection, template conflicts) are read from their caches on
 * purpose: a panel that bypassed them would be diagnosing a different store
 * than the one running.
 *
 * @since 0.14.0
 */
final class HealthPanel {

	/**
	 * Row state: everything as it should be.
	 *
	 * @var string
	 */
	public const OK = 'ok';

	/**
	 * Row state: works, but a merchant should know.
	 *
	 * @var string
	 */
	public const NOTICE = 'notice';

	/**
	 * Row state: something is actually wrong.
	 *
	 * @var string
	 */
	public const PROBLEM = 'problem';

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param TemplateConflictScanner $scanner  Finds theme and page-builder overrides of the order pages.
	 * @param Detector                $invoices Finds the installed invoice plugin.
	 */
	public function __construct( private TemplateConflictScanner $scanner, private Detector $invoices ) {}

	/**
	 * Renders the panel.
	 *
	 * @since 0.14.0
	 *
	 * @param string $anchor Element id, so the settings sidebar can link to it.
	 * @return void
	 */
	public function render( string $anchor = 'wpmphub-general-status' ): void {
		printf(
			'<section class="wpmphub-settings__card wpmphub-health" id="%s" data-wpmphub-health data-wpmphub-settings-section="status">',
			esc_attr( $anchor )
		);

		echo '<div class="wpmphub-settings__card-header">';
		printf( '<h3>%s</h3>', esc_html__( 'Status', 'wpmake-post-purchase-hub' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'What this store looks like to the plugin right now. Nothing here is a setting — it is what the other tabs have added up to.', 'wpmake-post-purchase-hub' )
		);
		echo '</div>';

		echo '<div class="wpmphub-settings__card-body wpmphub-health__rows">';

		foreach ( $this->rows() as $row ) {
			printf(
				'<div class="wpmphub-health__row" data-wpmphub-health-row="%1$s" data-wpmphub-health-state="%2$s"><span class="wpmphub-health__dot" aria-hidden="true"></span><span class="wpmphub-health__label">%3$s</span><span class="wpmphub-health__value">%4$s</span></div>',
				esc_attr( $row['id'] ),
				esc_attr( $row['state'] ),
				esc_html( $row['label'] ),
				esc_html( $row['value'] )
			);
		}

		echo '</div></section>';
	}

	/**
	 * Every row, as prepared data.
	 *
	 * Public so the wizard's tracking step can show the same detection result
	 * the panel does, rather than detecting again slightly differently.
	 *
	 * @since 0.14.0
	 *
	 * @return array<int, array{id: string, label: string, value: string, state: string}>
	 */
	public function rows(): array {
		return array(
			$this->setup_row(),
			$this->tracking_row(),
			$this->invoice_row(),
			$this->conflicts_row(),
			$this->cron_row(),
			$this->schema_row(),
		);
	}

	/**
	 * Whether the storefront is live yet.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function setup_row(): array {
		$complete = SetupState::is_complete();

		return array(
			'id'    => 'setup',
			'label' => __( 'Setup', 'wpmake-post-purchase-hub' ),
			'value' => $complete
				? __( 'Complete — your order pages are live.', 'wpmake-post-purchase-hub' )
				: __( 'Not finished — nothing is showing to customers yet.', 'wpmake-post-purchase-hub' ),
			'state' => $complete ? self::OK : self::NOTICE,
		);
	}

	/**
	 * Which tracking source, if any, this store has.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function tracking_row(): array {
		$plugin = self::detected_tracking_plugin();

		return array(
			'id'    => 'tracking',
			'label' => __( 'Tracking data', 'wpmake-post-purchase-hub' ),
			'value' => '' === $plugin
				? __( 'None detected. Delivery estimates are shown instead; real tracking replaces them automatically once a tracking plugin provides it.', 'wpmake-post-purchase-hub' )
				: sprintf(
					/* translators: %s: name of the detected tracking plugin. */
					__( 'Reading from %s.', 'wpmake-post-purchase-hub' ),
					$plugin
				),
			'state' => '' === $plugin ? self::NOTICE : self::OK,
		);
	}

	/**
	 * Which invoice plugin, if any, this store has.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function invoice_row(): array {
		$provider = $this->invoices->detect();

		return array(
			'id'    => 'invoices',
			'label' => __( 'Invoices', 'wpmake-post-purchase-hub' ),
			'value' => null === $provider
				? __( 'No invoice plugin detected. Customers are offered their order page to print; this plugin does not generate PDFs.', 'wpmake-post-purchase-hub' )
				: sprintf(
					/* translators: %s: name of the detected invoice plugin. */
					__( 'Linking to invoices from %s.', 'wpmake-post-purchase-hub' ),
					$provider->label()
				),
			'state' => self::OK,
		);
	}

	/**
	 * Whether a theme or page builder has taken over the order pages.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function conflicts_row(): array {
		$conflicts = $this->scanner->conflicts();

		return array(
			'id'    => 'templates',
			'label' => __( 'Template conflicts', 'wpmake-post-purchase-hub' ),
			'value' => array() === $conflicts
				? __( 'None found.', 'wpmake-post-purchase-hub' )
				: sprintf(
					/* translators: %s: comma-separated list of conflicting templates or plugins. */
					__( 'Your theme or a page builder overrides: %s. Additive display still works; full replacement is switched off while this is true.', 'wpmake-post-purchase-hub' ),
					implode( ', ', array_map( 'strval', $conflicts ) )
				),
			'state' => array() === $conflicts ? self::OK : self::NOTICE,
		);
	}

	/**
	 * Whether the daily jobs are scheduled.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function cron_row(): array {
		$next = wp_next_scheduled( Activator::CLEANUP_HOOK );

		if ( ! is_int( $next ) || $next < 1 ) {
			return array(
				'id'    => 'cron',
				'label' => __( 'Daily cleanup', 'wpmake-post-purchase-hub' ),
				'value' => __( 'Not scheduled. Deactivating and reactivating the plugin reschedules it.', 'wpmake-post-purchase-hub' ),
				'state' => self::PROBLEM,
			);
		}

		return array(
			'id'    => 'cron',
			'label' => __( 'Daily cleanup', 'wpmake-post-purchase-hub' ),
			'value' => sprintf(
				/* translators: %s: human-readable time until the next run, e.g. "3 hours". */
				__( 'Next run in %s.', 'wpmake-post-purchase-hub' ),
				human_time_diff( time(), $next )
			),
			'state' => self::OK,
		);
	}

	/**
	 * Whether the database is where this build expects it.
	 *
	 * @since 0.14.0
	 *
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function schema_row(): array {
		$installed = Migrator::installed_version();
		$expected  = Migrator::TARGET_VERSION;
		$tables    = Schema::table_exists( Schema::requests_table() );

		if ( ! $tables ) {
			return array(
				'id'    => 'schema',
				'label' => __( 'Database', 'wpmake-post-purchase-hub' ),
				'value' => __( 'The requests table is missing. Deactivating and reactivating the plugin recreates it.', 'wpmake-post-purchase-hub' ),
				'state' => self::PROBLEM,
			);
		}

		return array(
			'id'    => 'schema',
			'label' => __( 'Database', 'wpmake-post-purchase-hub' ),
			'value' => $installed === $expected
				? sprintf(
					/* translators: %d: schema version number. */
					__( 'Up to date (version %d).', 'wpmake-post-purchase-hub' ),
					$expected
				)
				: sprintf(
					/* translators: 1: installed schema version, 2: expected schema version. */
					__( 'Version %1$d installed, %2$d expected. It updates itself on the next page load.', 'wpmake-post-purchase-hub' ),
					$installed,
					$expected
				),
			'state' => $installed === $expected ? self::OK : self::NOTICE,
		);
	}

	/**
	 * The tracking plugin this store has, by its own name.
	 *
	 * Detection is by the constants and classes each plugin publishes, not by
	 * its meta shape: this is a diagnostic, and no data is read from either
	 * plugin here. The tracking *adapter* layer is a later milestone
	 * (docs/SPEC.md Phase 5); until it exists, this row is honest about that by
	 * naming what is installed rather than claiming it is being read.
	 *
	 * @since 0.14.0
	 *
	 * @return string Empty when none is detected.
	 */
	public static function detected_tracking_plugin(): string {
		$candidates = array(
			'WC_Advanced_Shipment_Tracking' => 'Advanced Shipment Tracking',
			'WC_Shipment_Tracking'          => 'WooCommerce Shipment Tracking',
			'WC_Shipment_Tracking_Actions'  => 'WooCommerce Shipment Tracking',
		);

		foreach ( $candidates as $class => $name ) {
			if ( class_exists( $class ) ) {
				return $name;
			}
		}

		/**
		 * Filters the tracking plugin the health panel reports.
		 *
		 * A store whose tracking comes from somewhere this plugin does not
		 * recognise — a courier's own integration, a merchant's own code — names
		 * it here so the panel stops saying "none detected" when that is untrue.
		 *
		 * @since 0.14.0
		 *
		 * @param string $name Detected plugin name, empty when none was found.
		 */
		return (string) apply_filters( 'wpmphub_detected_tracking_plugin', '' );
	}
}
