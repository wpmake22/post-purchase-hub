<?php
/**
 * Template resolution with theme-override support.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Support\Logger;

/**
 * Finds and renders this plugin's templates.
 *
 * Names come from a hardcoded list and nothing else. A template name that
 * reached this class from a request — a shortcode attribute, a query var, a
 * block attribute — would be a local file inclusion, so the list is the whole
 * of the input validation and there is no sanitising path around it.
 *
 * Resolution order is the WooCommerce convention a theme author already knows:
 * the active theme, then its parent, then the plugin's own copy. Resolved paths
 * are memoised for the request because the orders list renders the same partial
 * once per row and locate_template() hits the filesystem every time.
 *
 * @since 0.4.0
 */
final class TemplateLoader {

	/**
	 * Directory a theme places its overrides in.
	 *
	 * @var string
	 */
	public const THEME_DIRECTORY = 'post-purchase-hub';

	/**
	 * Every template this plugin will render, relative to templates/.
	 *
	 * @var string[]
	 */
	private const TEMPLATES = array(
		'partials/timeline.php',
		'partials/timeline-summary.php',
		'partials/orders-list.php',
		'partials/order-notes.php',
		'myaccount/orders.php',
		'myaccount/view-order.php',
	);

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Resolved absolute paths, keyed by template name.
	 *
	 * @var array<string, string>
	 */
	private array $located = array();

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Whether a name is one this plugin ships.
	 *
	 * @since 0.4.0
	 *
	 * @param string $name Template name relative to templates/.
	 * @return bool
	 */
	public function is_known( string $name ): bool {
		return in_array( $name, self::TEMPLATES, true );
	}

	/**
	 * The absolute path a template resolves to.
	 *
	 * @since 0.4.0
	 *
	 * @param string $name Template name relative to templates/.
	 * @return string|null Null when the name is unknown or nothing readable was found.
	 */
	public function locate( string $name ): ?string {
		if ( isset( $this->located[ $name ] ) ) {
			return $this->located[ $name ];
		}

		if ( ! $this->is_known( $name ) ) {
			$this->logger->error(
				'Refused to locate a template that is not on the allow list.',
				array( 'template' => $name )
			);

			return null;
		}

		$path = locate_template( array( self::THEME_DIRECTORY . '/' . $name ) );

		if ( '' === $path ) {
			$path = PPH_PLUGIN_DIR . 'templates/' . $name;
		}

		/**
		 * Filters the absolute path a Post-Purchase Hub template resolves to.
		 *
		 * Runs after the theme has had its say, so a callback here overrides a
		 * theme override. The path is checked for existence afterwards; a path
		 * that is not readable is discarded and the plugin's own copy is used.
		 *
		 * @since 0.4.0
		 *
		 * @param string $path Absolute path to the template.
		 * @param string $name Template name relative to templates/.
		 */
		$filtered = (string) apply_filters( 'pph_locate_template', $path, $name );

		if ( $filtered !== $path && ! is_readable( $filtered ) ) {
			$this->logger->warning(
				'Ignored a pph_locate_template path that is not readable.',
				array(
					'template' => $name,
					'path'     => $filtered,
				)
			);
		} else {
			$path = $filtered;
		}

		if ( ! is_readable( $path ) ) {
			$this->logger->error(
				'Template is missing.',
				array(
					'template' => $name,
					'path'     => $path,
				)
			);

			return null;
		}

		$this->located[ $name ] = $path;

		return $path;
	}

	/**
	 * Renders a template.
	 *
	 * Silently renders nothing when the template cannot be resolved: a missing
	 * partial must degrade to an absent section, never to a fatal on a
	 * customer's order page.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $name Template name relative to templates/.
	 * @param array<string, mixed> $vars Variables the template documents.
	 * @return void
	 */
	public function render( string $name, array $vars = array() ): void {
		$path = $this->locate( $name );

		if ( null === $path ) {
			return;
		}

		self::include_template( $path, $vars );
	}

	/**
	 * Renders a template and returns the markup.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $name Template name relative to templates/.
	 * @param array<string, mixed> $vars Variables the template documents.
	 * @return string
	 */
	public function get( string $name, array $vars = array() ): string {
		ob_start();

		$this->render( $name, $vars );

		return (string) ob_get_clean();
	}

	/**
	 * Includes a template with only its own variables in scope.
	 *
	 * Static and argument-only so nothing from the loader leaks into template
	 * scope; a template that can see `$this` is a template that can query.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $pph_path Absolute path to the template.
	 * @param array<string, mixed> $pph_vars Variables the template documents.
	 * @return void
	 */
	private static function include_template( string $pph_path, array $pph_vars ): void {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Templates receive documented named variables, as WooCommerce's own wc_get_template() does. The array is built in this plugin, never from a request.
		extract( $pph_vars, EXTR_SKIP );

		include $pph_path;
	}
}
