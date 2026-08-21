<?php
/**
 * Admin asset loading, scoped to this plugin's own screens.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

/**
 * Loads the admin stylesheet and the confirmation script on four screens, and
 * nowhere else in wp-admin.
 *
 * The same discipline `Frontend\Assets` applies to the storefront: a plugin
 * that ships one global admin stylesheet slows down every screen of somebody
 * else's wp-admin to style its own. The gate is the screen's `page` parameter,
 * because that is what actually distinguishes our pages, and the order-edit
 * screen is included because `Admin\OrderMetabox` draws there too.
 *
 * @since 0.14.0
 */
final class Assets {

	/**
	 * Stylesheet handle.
	 *
	 * @var string
	 */
	public const STYLE_HANDLE = 'pph-admin';

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'pph-admin';

	/**
	 * Build directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const BUILD_PATH = 'assets/build/';

	/**
	 * Wires the enqueue hook.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues on our screens only.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! self::is_ours() ) {
			return;
		}

		$asset = $this->manifest( 'admin.asset.php' );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'admin.css',
			array(),
			$asset['version']
		);

		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	/**
	 * Whether the current admin screen is one of this plugin's.
	 *
	 * @since 0.14.0
	 * @return bool
	 */
	public static function is_ours(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding whether one stylesheet is needed on an admin GET; the value is only ever compared against a fixed list.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$ours = in_array( $page, array( SettingsPage::PAGE, Wizard::PAGE, Menu::REQUESTS_PAGE ), true );

		/**
		 * Filters whether this plugin's admin assets load on this screen.
		 *
		 * @since 0.14.0
		 *
		 * @param bool   $ours Whether to enqueue.
		 * @param string $page The `page` query argument, empty on screens that have none.
		 */
		return (bool) apply_filters( 'pph_enqueue_admin_assets', $ours, $page );
	}

	/**
	 * Reads the build manifest's version and dependencies.
	 *
	 * @since 0.14.0
	 *
	 * @param string $filename Manifest filename, relative to the build directory.
	 * @return array{version: string, dependencies: string[]}
	 */
	private function manifest( string $filename ): array {
		$path = PPH_PLUGIN_DIR . self::BUILD_PATH . $filename;

		if ( is_readable( $path ) ) {
			$asset = include $path;

			if ( is_array( $asset ) ) {
				return array(
					'version'      => isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PPH_VERSION,
					'dependencies' => isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
				);
			}
		}

		// A build that produced no manifest is a packaging fault, not a reason
		// to serve an unversioned asset forever.
		return array(
			'version'      => PPH_VERSION,
			'dependencies' => array(),
		);
	}
}
