<?php
/**
 * Detection of theme overrides on the templates replacement mode would take over.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Support\Cache;

/**
 * Reports whether the active theme already owns the order templates.
 *
 * Replacement mode swaps two WooCommerce templates for this plugin's. On a
 * store whose theme has copied those files — which is most stores running a
 * commercial theme — that swap silently discards the theme's layout, and the
 * merchant's first sign of it is a customer complaint. So the swap is refused
 * while a conflict stands, and this is what decides.
 *
 * Two kinds of conflict are looked for. A theme that copied one of those files
 * is found on disk. A page builder that has taken over the My Account page
 * leaves no template at all — it renders the page from its own stored layout —
 * so those are found by the marks the builders leave on the page itself.
 *
 * Neither check is exhaustive, and a builder this does not know about will pass
 * a clean scan. That is the reason replacement stays opt-in even when nothing is
 * found: a scan can prove a conflict exists, never that none does.
 *
 * @since 0.4.0
 */
final class TemplateConflictScanner {

	/**
	 * WooCommerce templates replacement mode would take over.
	 *
	 * @var string[]
	 */
	public const WATCHED = array(
		'myaccount/orders.php',
		'myaccount/view-order.php',
	);

	/**
	 * Post meta a page builder sets when it owns a page's layout.
	 *
	 * Keyed by builder, so the report names what a merchant has to go and look
	 * at rather than telling them "something".
	 *
	 * @var array<string, array{meta: string, value: string}>
	 */
	private const BUILDER_META = array(
		'elementor'      => array(
			'meta'  => '_elementor_edit_mode',
			'value' => 'builder',
		),
		'beaver-builder' => array(
			'meta'  => '_fl_builder_enabled',
			'value' => '1',
		),
		'wpbakery'       => array(
			'meta'  => '_wpb_vc_js_status',
			'value' => 'true',
		),
	);

	/**
	 * Content markers a page builder leaves in the My Account page.
	 *
	 * @var array<string, string>
	 */
	private const BUILDER_CONTENT = array(
		'divi'      => '[et_pb_',
		'elementor' => '<!-- wp:elementor',
	);

	/**
	 * Cache key holding the scan result.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'template_conflicts';

	/**
	 * Prefix distinguishing a builder conflict from a template override.
	 *
	 * @var string
	 */
	public const BUILDER_PREFIX = 'page-builder/';

	/**
	 * How long a scan is trusted, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Cache $cache Cache.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Drops the cached scan when the active theme changes.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'switch_theme', array( $this, 'forget' ) );
	}

	/**
	 * The overridden templates, as template name => absolute path.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $refresh Skip the cached answer and look again.
	 * @return array<string, string>
	 */
	public function conflicts( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = $this->cache->get( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$found = array();

		foreach ( self::WATCHED as $template ) {
			$path = locate_template( array( $this->woocommerce_path() . $template ) );

			if ( '' !== $path ) {
				$found[ $template ] = $path;
			}
		}

		$found = array_merge( $found, $this->builder_conflicts() );

		$this->cache->set( self::CACHE_KEY, $found, self::TTL );

		return $found;
	}

	/**
	 * Page builders that have taken over the My Account page.
	 *
	 * A builder-rendered account page never calls WooCommerce's templates, so
	 * replacing them changes nothing the customer sees while the merchant is
	 * told it worked. Reporting it is the only honest outcome.
	 *
	 * @since 0.4.1
	 *
	 * @return array<string, string> Conflict key => the builder's name.
	 */
	private function builder_conflicts(): array {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;

		if ( $page_id < 1 ) {
			return array();
		}

		$found = array();

		foreach ( self::BUILDER_META as $builder => $marker ) {
			if ( (string) get_post_meta( $page_id, $marker['meta'], true ) === $marker['value'] ) {
				$found[ self::BUILDER_PREFIX . $builder ] = $builder;
			}
		}

		// get_post_field() runs the value through sanitize_post_field(), whose
		// filters a third-party plugin can point at anything; a non-string
		// means no marker can be present rather than something to cast.
		$content = get_post_field( 'post_content', $page_id );
		$content = is_string( $content ) ? $content : '';

		foreach ( self::BUILDER_CONTENT as $builder => $marker ) {
			if ( '' !== $content && str_contains( $content, $marker ) ) {
				$found[ self::BUILDER_PREFIX . $builder ] = $builder;
			}
		}

		return $found;
	}

	/**
	 * Whether the theme owns any of the watched templates.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $refresh Skip the cached answer and look again.
	 * @return bool
	 */
	public function has_conflicts( bool $refresh = false ): bool {
		return array() !== $this->conflicts( $refresh );
	}

	/**
	 * Drops the cached scan.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function forget(): void {
		$this->cache->delete( self::CACHE_KEY );
	}

	/**
	 * The directory a theme places WooCommerce overrides in.
	 *
	 * Read from WooCommerce rather than hardcoded, because a site can filter it.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	private function woocommerce_path(): string {
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'template_path' ) ) ) {
			return (string) WC()->template_path();
		}

		return 'woocommerce/';
	}
}
