<?php
/**
 * Conditional frontend asset loading.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Rest\LookupController;
use PostPurchaseHub\Rest\ReorderController;
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
	 * Guest-lookup script handle.
	 *
	 * @var string
	 */
	public const LOOKUP_HANDLE = 'pph-lookup';

	/**
	 * Reorder-confirmation script handle.
	 *
	 * @var string
	 */
	public const REORDER_HANDLE = 'pph-reorder';

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

		$this->enqueue_lookup();
		$this->enqueue_reorder();
	}

	/**
	 * Enqueues the reorder confirmation, on the one render that carries the form.
	 *
	 * Scoped tighter than the rest: the confirmation form exists only while a
	 * customer is looking at a reconciliation summary, which is a single order
	 * page carrying a single query argument. Loading it on every order page
	 * would ship a script for a button that is not there.
	 *
	 * @since 0.12.0
	 * @return void
	 */
	private function enqueue_reorder(): void {
		if ( ! $this->renders_reorder_summary() ) {
			return;
		}

		$script = $this->manifest( 'reorder.asset.php' );

		wp_enqueue_script(
			self::REORDER_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'reorder.js',
			$script['dependencies'],
			$script['version'],
			true
		);

		wp_localize_script(
			self::REORDER_HANDLE,
			'pphReorder',
			array(
				'restUrl' => rest_url( ReorderController::NAMESPACE . ReorderController::ROUTE ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Whether this request is the reorder summary being drawn.
	 *
	 * @since 0.12.0
	 * @return bool
	 */
	private function renders_reorder_summary(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding whether one script is needed on a GET; the value is never used as anything but a presence test here.
		return isset( $_GET[ Reorder::QUERY_ARG ] ) && absint( wp_unslash( $_GET[ Reorder::QUERY_ARG ] ) ) > 0;
	}

	/**
	 * Enqueues the lookup enhancement, on the pages that carry the form.
	 *
	 * Kept separate from the script above, and off by default, because the
	 * lookup form lives on one page a store may not even have while the request
	 * modal lives on the account pages. No REST nonce is localised: the lookup
	 * route deliberately takes none (see Rest\LookupController), and a nonce
	 * baked into a cacheable page would go stale anyway.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	private function enqueue_lookup(): void {
		if ( ! $this->post_embeds_lookup() ) {
			return;
		}

		$script = $this->manifest( 'lookup.asset.php' );

		wp_enqueue_script(
			self::LOOKUP_HANDLE,
			PPH_PLUGIN_URL . self::BUILD_PATH . 'lookup.js',
			$script['dependencies'],
			$script['version'],
			true
		);

		wp_localize_script(
			self::LOOKUP_HANDLE,
			'pphLookup',
			array(
				'restUrl'      => rest_url( LookupController::NAMESPACE . LookupController::ROUTE ),
				'errorMessage' => __( 'That could not be submitted. Please check your connection and try again.', 'post-purchase-hub' ),
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
		 * content embeds one of this plugin's shortcodes or blocks. A surface
		 * rendered somewhere else — a custom page template, a widget, a lookup
		 * form moved into a theme file — has to say so here, because guessing
		 * would mean loading everywhere.
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

		return has_shortcode( $post->post_content, Shortcodes::TAG )
			|| has_block( Blocks::NAME, $post )
			|| $this->post_embeds_lookup();
	}

	/**
	 * Whether the queried post embeds the lookup form.
	 *
	 * @since 0.11.0
	 * @return bool
	 */
	private function post_embeds_lookup(): bool {
		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, LookupForm::TAG ) || has_block( Blocks::LOOKUP_NAME, $post );
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
