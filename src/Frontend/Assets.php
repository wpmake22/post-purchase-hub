<?php
/**
 * Conditional frontend asset loading.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Rest\RequestsController;

/**
 * Loads this plugin's stylesheet and request-modal script on the pages that
 * render them, and nowhere else.
 *
 * A plugin that ships one global stylesheet is a plugin that slows down every
 * product page on the store to style four of them, so the scope is decided per
 * request and the default is "no". Each build manifest supplies its own
 * version and dependencies, so a deployed change busts caches without anyone
 * remembering to bump a constant.
 *
 * @since 0.4.0
 */
final class Assets {

	/**
	 * Stylesheet handle.
	 *
	 * @var string
	 */
	public const STYLE_HANDLE = 'pph-frontend';

	/**
	 * Request-modal script handle.
	 *
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'pph-requests';

	/**
	 * Build directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const BUILD_PATH = 'assets/build/';

	/**
	 * Wires the enqueue hook.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the stylesheet and script when this request will render something.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_required() ) {
			return;
		}

		$style = $this->manifest( 'index.asset.php' );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'index.css',
			array(),
			$style['version']
		);

		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );

		$script = $this->manifest( 'requests.asset.php' );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'requests.js',
			$script['dependencies'],
			$script['version'],
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'pphRequests',
			array(
				'restUrl' => rest_url( RequestsController::NAMESPACE . RequestsController::ROUTE ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Whether this request renders any of this plugin's markup.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function is_required(): bool {
		$required = $this->is_order_endpoint() || $this->post_embeds_us();

		/**
		 * Filters whether this plugin's frontend assets load on this request.
		 *
		 * The default covers the My Account order endpoints and any post whose
		 * content embeds the shortcode or the block. A surface rendered somewhere
		 * else — a custom page template, a widget, the guest lookup page — has to
		 * say so here, because guessing would mean loading everywhere.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $required Whether to enqueue.
		 */
		return (bool) apply_filters( 'pph_enqueue_assets', $required );
	}

	/**
	 * Whether the request is a My Account endpoint that shows orders.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private function is_order_endpoint(): bool {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}

		return is_wc_endpoint_url( 'orders' ) || is_wc_endpoint_url( 'view-order' );
	}

	/**
	 * Whether the queried post embeds the shortcode or the block.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private function post_embeds_us(): bool {
		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, Shortcodes::TAG ) || has_block( Blocks::NAME, $post );
	}

	/**
	 * Reads one build manifest's version and dependencies.
	 *
	 * @since 0.4.0
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

		// A build that did not produce a manifest is a packaging fault, not a
		// reason to serve an unversioned, dependency-less asset forever.
		return array(
			'version'      => PPH_VERSION,
			'dependencies' => array(),
		);
	}
}
