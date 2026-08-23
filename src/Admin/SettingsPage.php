<?php
/**
 * The six-tab settings screen.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * WooCommerce → Post-Purchase Hub → Settings, on the Settings API.
 *
 * One option (`pph_settings`), six tabs, and one sanitisation pass per save.
 * `SettingsLayout` draws the two-pane chrome around whatever this routes to.
 * The tabs post only their own fields, so `SettingsSanitizer::sanitize_tab()`
 * merges over what is stored rather than replacing it — otherwise saving the
 * Timeline tab would blank the Actions tab, which is the classic
 * one-option-many-tabs bug.
 *
 * Rendering is deliberately dull: `SettingsFields` says what exists,
 * `SettingsRenderer` draws one field, and this class routes, registers and
 * saves. Nothing here decides plugin behaviour — the services that read
 * `pph_settings` do, and they were reading it before this screen existed.
 *
 * @since 0.14.0
 */
final class SettingsPage {

	/**
	 * Capability required to see or change settings.
	 *
	 * WooCommerce's own configuration capability, not the queue's
	 * `edit_shop_orders`: handling a customer's cancellation request and
	 * reconfiguring the store are different jobs, and a shop manager who can do
	 * the first should not necessarily be reshaping the timeline.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE = 'pph-settings';

	/**
	 * Settings group, per tab, as register_setting() needs one.
	 *
	 * @var string
	 */
	public const GROUP_PREFIX = 'pph_settings_';

	/**
	 * Hidden field naming the tab a save came from.
	 *
	 * @var string
	 */
	public const TAB_FIELD = 'pph_tab';

	/**
	 * Constructor.
	 *
	 * @since 0.14.0
	 *
	 * @param StageMap       $stages Supplies the stage list and detected statuses the Timeline tab offers.
	 * @param SettingsLayout $layout Draws the sidebar, the cards and the save bar.
	 */
	public function __construct( private StageMap $stages, private SettingsLayout $layout ) {}

	/**
	 * Wires the menu entry and the Settings API registration.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the submenu entry beneath WooCommerce, next to the request queue.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Post-Purchase Hub settings', 'post-purchase-hub' ),
			__( 'Post-Purchase Hub', 'post-purchase-hub' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option once per tab group.
	 *
	 * Each tab gets its own group so `settings_fields()` emits a nonce scoped to
	 * the tab being saved, and core's own `options.php` handler does the
	 * capability check for us — with `manage_woocommerce` declared here rather
	 * than relying on its `manage_options` default, which a shop manager does
	 * not have.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( SettingsFields::TABS as $tab ) {
			register_setting(
				self::GROUP_PREFIX . $tab,
				SettingsFields::OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize' ),
					'default'           => array(),
					'show_in_rest'      => false,
				)
			);
		}

		foreach ( SettingsFields::TABS as $tab ) {
			add_filter( 'option_page_capability_' . self::GROUP_PREFIX . $tab, array( $this, 'capability' ) );
		}
	}

	/**
	 * The capability core's options handler should require.
	 *
	 * @since 0.14.0
	 *
	 * @return string
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Sanitises a save, scoped to the tab it came from.
	 *
	 * @since 0.14.0
	 *
	 * @param mixed $raw Posted option value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $raw ): array {
		$existing = get_option( SettingsFields::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		return SettingsSanitizer::sanitize_tab( $raw, self::posted_tab(), $existing );
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'post-purchase-hub' ) );
		}

		$tab = self::current_tab();

		$this->layout->open( $tab );

		if ( array() === SettingsFields::for_tab( $tab ) ) {
			$this->render_signpost_tab( $tab );
		} else {
			$this->render_form( $tab );
		}

		$this->layout->close();
	}

	/**
	 * One tab's form.
	 *
	 * @since 0.14.0
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private function render_form( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" data-pph-settings-form>';

		settings_fields( self::GROUP_PREFIX . $tab );

		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::TAB_FIELD ), esc_attr( $tab ) );

		$this->layout->render_sections( $tab, new SettingsRenderer( $this->stages, self::stored() ) );

		echo '<div class="pph-settings__save">';

		submit_button( __( 'Save changes', 'post-purchase-hub' ), 'primary', 'submit', false );

		echo '</div></form>';
	}

	/**
	 * A tab that configures nothing itself, and says where its settings live.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private function render_signpost_tab( string $tab ): void {
		echo '<div class="pph-settings__cards" data-pph-settings-cards>';

		if ( 'emails' === $tab ) {
			$this->layout->open_card(
				SettingsLayout::anchor( $tab, 'emails' ),
				__( 'Where these emails are configured', 'post-purchase-hub' ),
				'',
				'emails'
			);

			self::render_emails_tab();

			SettingsLayout::close_card();
		}

		echo '</div>';
	}

	/**
	 * The Emails tab, which deliberately configures nothing itself.
	 *
	 * Every email this plugin sends is a `WC_Email` with its own recipient,
	 * subject and on/off switch on WooCommerce's own Emails screen. Restating
	 * those here would be a second place to change the same thing, so this tab
	 * points at the first.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	private static function render_emails_tab(): void {
		echo '<div class="pph-settings__panel" data-pph-settings-emails>';

		printf(
			'<p>%s</p>',
			esc_html__( 'This plugin\'s emails live with your other WooCommerce emails, so you change their recipients, subjects and wording in one place rather than two.', 'post-purchase-hub' )
		);

		printf(
			'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ),
			esc_html__( 'Open WooCommerce email settings', 'post-purchase-hub' )
		);

		echo '</div>';
	}

	/**
	 * The tab being viewed.
	 *
	 * @since 0.14.0
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation between tabs; nothing is written on this path.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return SettingsFields::is_tab( $tab ) ? $tab : 'general';
	}

	/**
	 * The tab a save came from.
	 *
	 * Read from the form's own hidden field rather than the query string,
	 * because core posts to `options.php` and the referring tab is not in its
	 * URL. Only ever used to decide *which declared fields to read*, so a
	 * forged value can still write nothing that is not declared.
	 *
	 * @since 0.14.0
	 * @return string
	 */
	private static function posted_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by core's options.php before this sanitise callback runs.
		$tab = isset( $_POST[ self::TAB_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::TAB_FIELD ] ) ) : '';

		return SettingsFields::is_tab( $tab ) ? $tab : 'general';
	}

	/**
	 * The URL of one tab.
	 *
	 * @since 0.14.0
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The settings screen's own URL.
	 *
	 * @since 0.14.0
	 * @return string
	 */
	public static function url(): string {
		return self::tab_url( 'general' );
	}

	/**
	 * Stored settings, with defaults filled in so the form always has a value
	 * to show and an unsaved store renders the same as a saved-with-defaults one.
	 *
	 * @since 0.14.0
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = get_option( SettingsFields::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array_merge( SettingsFields::defaults(), $stored );

		// Two composite values whose defaults are computed rather than declared.
		if ( ! isset( $stored[ ActionAvailability::SETTING ] ) ) {
			$settings[ ActionAvailability::SETTING ] = ActionAvailability::all();
		}

		if ( ! isset( $stored[ StageMapConfig::MAP_SETTING ] ) ) {
			$settings[ StageMapConfig::MAP_SETTING ] = array();
		}

		return $settings;
	}
}
