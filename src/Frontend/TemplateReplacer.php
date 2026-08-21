<?php
/**
 * Opt-in replacement of WooCommerce's order templates.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Admin\TemplateConflictScanner;

/**
 * Swaps two WooCommerce templates for this plugin's, when asked and when safe.
 *
 * Off by default, per hard rule 14: additive rendering is the mode that has to
 * work everywhere, and replacement is the mode a merchant chooses after seeing
 * it work. Enabling it while the theme owns those templates would throw the
 * theme's layout away, so the conflict scan is a condition of the swap and not
 * merely a warning next to it.
 *
 * The swap runs on `wc_get_template` rather than the more obvious
 * `woocommerce_locate_template`. `wc_get_template()` memoises located paths in
 * the object cache under a key of template name and WooCommerce version, and it
 * caches what `wc_locate_template()` returned — filters included. A swap made
 * there would therefore outlive the setting being switched off, leaving a
 * merchant with a template they have already told us to stop using. The
 * `wc_get_template` filter is applied after that cache is read, on every call,
 * so the setting takes effect the moment it changes.
 *
 * @since 0.4.0
 */
final class TemplateReplacer {

	/**
	 * Settings key choosing the rendering mode.
	 *
	 * @var string
	 */
	public const SETTING = 'template_mode';

	/**
	 * Additive rendering: WooCommerce's templates, plus our sections.
	 *
	 * @var string
	 */
	public const MODE_ADDITIVE = 'additive';

	/**
	 * Replacement rendering: our templates instead of WooCommerce's.
	 *
	 * @var string
	 */
	public const MODE_REPLACEMENT = 'replacement';

	/**
	 * WooCommerce template name => our template name.
	 *
	 * @var array<string, string>
	 */
	private const REPLACEMENTS = array(
		'myaccount/orders.php'     => 'myaccount/orders.php',
		'myaccount/view-order.php' => 'myaccount/view-order.php',
	);

	/**
	 * Template loader.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Conflict scanner.
	 *
	 * @var TemplateConflictScanner
	 */
	private TemplateConflictScanner $scanner;

	/**
	 * Memoised decision for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $enabled = null;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param TemplateLoader          $templates Template loader.
	 * @param TemplateConflictScanner $scanner   Conflict scanner.
	 */
	public function __construct( TemplateLoader $templates, TemplateConflictScanner $scanner ) {
		$this->templates = $templates;
		$this->scanner   = $scanner;
	}

	/**
	 * Wires the swap.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wc_get_template', array( $this, 'replace' ), 10, 2 );
	}

	/**
	 * Returns this plugin's template in place of WooCommerce's.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $template      Absolute path WooCommerce resolved.
	 * @param mixed $template_name Template name being rendered.
	 * @return mixed
	 */
	public function replace( $template, $template_name = '' ) {
		if ( ! is_string( $template_name ) || ! isset( self::REPLACEMENTS[ $template_name ] ) ) {
			return $template;
		}

		if ( ! $this->is_enabled() ) {
			return $template;
		}

		$replacement = $this->templates->locate( self::REPLACEMENTS[ $template_name ] );

		return null === $replacement ? $template : $replacement;
	}

	/**
	 * Whether replacement is both chosen and safe.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function is_enabled(): bool {
		if ( null !== $this->enabled ) {
			return $this->enabled;
		}

		$this->enabled = self::MODE_REPLACEMENT === $this->mode() && ! $this->scanner->has_conflicts();

		return $this->enabled;
	}

	/**
	 * Whether the merchant has asked for replacement, conflicts aside.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function is_requested(): bool {
		return self::MODE_REPLACEMENT === $this->mode();
	}

	/**
	 * The configured rendering mode.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	private function mode(): string {
		$settings = get_option( 'pph_settings', array() );
		$mode     = is_array( $settings ) ? ( $settings[ self::SETTING ] ?? self::MODE_ADDITIVE ) : self::MODE_ADDITIVE;

		/**
		 * Filters the order-page rendering mode.
		 *
		 * Either `additive`, which adds sections to WooCommerce's own templates,
		 * or `replacement`, which serves this plugin's templates instead. Anything
		 * else is treated as additive: the mode that cannot break a theme is the
		 * one an unrecognised value should fall back to.
		 *
		 * @since 0.4.0
		 *
		 * @param string $mode Configured mode.
		 */
		$mode = (string) apply_filters( 'pph_template_mode', is_string( $mode ) ? $mode : self::MODE_ADDITIVE );

		return self::MODE_REPLACEMENT === $mode ? self::MODE_REPLACEMENT : self::MODE_ADDITIVE;
	}
}
