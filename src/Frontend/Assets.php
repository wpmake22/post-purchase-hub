<?php
/**
 * Conditional frontend asset loading.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

/**
 * Loads this plugin's stylesheet on the pages that render it, and nowhere else.
 *
 * A plugin that ships one global stylesheet is a plugin that slows down every
 * product page on the store to style four of them, so the scope is decided per
 * request and the default is "no". The build manifest supplies the version, so
 * a deployed change busts caches without anyone remembering to bump a constant.
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
	 * Enqueues the stylesheet when this request will render something.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_required() ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'index.css',
			array(),
			$this->version()
		);

		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
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
	 * The build's version string.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	private function version(): string {
		$manifest = PPH_PLUGIN_DIR . self::BUILD_PATH . 'index.asset.php';

		if ( is_readable( $manifest ) ) {
			$asset = include $manifest;

			if ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) ) {
				return $asset['version'];
			}
		}

		// A build that did not produce a manifest is a packaging fault, not a
		// reason to serve an unversioned stylesheet forever.
		return PPH_VERSION;
	}
}
