<?php
/**
 * The admin page the setup wizard's React app mounts into.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Rest\SetupController;

/**
 * A full-screen page of its own, deliberately outside wp-admin's chrome.
 *
 * The wizard takes over the window — no admin menu, no notices, no other
 * plugin's banner competing with the one decision on the screen. That is what
 * the page callback being useless here buys: `render_page()` runs on
 * `admin_init`, prints a whole document and exits, before wp-admin has drawn
 * its sidebar. The registered submenu still exists because the capability check
 * and the `page` slug come from it, and because without it WordPress refuses
 * the URL entirely.
 *
 * No menu entry: the wizard is reached from the activation notice and from the
 * settings screen, not from a permanent "Setup" item that would still be there
 * years later offering to configure a store that was configured on day one.
 *
 * Everything the app does afterwards goes through `Rest\SetupController`. The
 * only thing handed over inline is the REST root and a nonce — a wizard that
 * needs its questions localised into the page would be a wizard whose questions
 * live in two places.
 *
 * @since 0.15.0
 */
final class WizardPage {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const PAGE = 'wpmphub-setup';

	/**
	 * Capability required to run setup.
	 *
	 * @var string
	 */
	public const CAPABILITY = SettingsPage::CAPABILITY;

	/**
	 * Script and style handle.
	 *
	 * @var string
	 */
	public const HANDLE = 'wpmphub-setup';

	/**
	 * Element the app mounts into.
	 *
	 * @var string
	 */
	public const ROOT_ID = 'wpmphub-setup-wizard';

	/**
	 * Build directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const BUILD_PATH = 'assets/build/';

	/**
	 * Wires the page.
	 *
	 * Priority 30 on `admin_init` matches the point WordPress has finished
	 * loading plugins and set the current user, so `current_user_can()` is
	 * answerable and no other plugin has started printing yet.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'render_page' ), 30 );
	}

	/**
	 * Registers the page without a menu entry of its own.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'',
			__( 'Post-Purchase Hub setup', 'wpmake-post-purchase-hub' ),
			__( 'Setup', 'wpmake-post-purchase-hub' ),
			self::CAPABILITY,
			self::PAGE,
			'__return_null'
		);
	}

	/**
	 * Prints the wizard document and stops, on our page and nowhere else.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! self::is_current_screen() ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'wpmake-post-purchase-hub' ), '', array( 'response' => 403 ) );
		}

		$this->enqueue();
		$this->print_document();

		exit;
	}

	/**
	 * Whether this request is for the wizard page.
	 *
	 * @since 0.15.0
	 * @return bool
	 */
	public static function is_current_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding which admin screen was asked for on a GET; the value is only ever compared against a fixed slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::PAGE === $page;
	}

	/**
	 * Enqueues the app and the data it needs to reach the REST API.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	private function enqueue(): void {
		$asset = $this->manifest();

		wp_enqueue_script(
			self::HANDLE,
			WPMPHUB_PLUGIN_URL . self::BUILD_PATH . 'setup.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			WPMPHUB_PLUGIN_URL . self::BUILD_PATH . 'setup.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		wp_set_script_translations( self::HANDLE, 'wpmake-post-purchase-hub' );

		wp_add_inline_script(
			self::HANDLE,
			'window.wpmphubSetup = ' . wp_json_encode( self::app_data() ) . ';',
			'before'
		);
	}

	/**
	 * The handful of values the app cannot ask the REST API for, because they
	 * are what it needs in order to ask.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, mixed>
	 */
	private static function app_data(): array {
		return array(
			'root'       => esc_url_raw( rest_url( SetupController::NAMESPACE . SetupController::ROUTE ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'exitUrl'    => SettingsPage::url(),
			'dashboard'  => admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE ),
			'pluginName' => __( 'Post-Purchase Hub', 'wpmake-post-purchase-hub' ),
			'storeName'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Prints the document the app owns entirely.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	private function print_document(): void {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php esc_html_e( 'Set up Post-Purchase Hub', 'wpmake-post-purchase-hub' ); ?></title>
			<?php
			wp_print_styles();
			wp_print_head_scripts();
			?>
		</head>
		<body class="wpmphub-setup-body">
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" data-wpmphub-wizard></div>
			<?php wp_print_footer_scripts(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Reads the build manifest's version and dependencies.
	 *
	 * @since 0.15.0
	 *
	 * @return array{version: string, dependencies: string[]}
	 */
	private function manifest(): array {
		$path = WPMPHUB_PLUGIN_DIR . self::BUILD_PATH . 'setup.asset.php';

		if ( is_readable( $path ) ) {
			$asset = include $path;

			if ( is_array( $asset ) ) {
				return array(
					'version'      => isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : WPMPHUB_VERSION,
					'dependencies' => isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
				);
			}
		}

		// A build that produced no manifest is a packaging fault, not a reason
		// to serve an unversioned asset forever.
		return array(
			'version'      => WPMPHUB_VERSION,
			'dependencies' => array(),
		);
	}

	/**
	 * The wizard's URL.
	 *
	 * @since 0.15.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}
}
