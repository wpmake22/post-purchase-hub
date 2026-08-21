<?php
/**
 * In-memory stand-in for the WordPress functions the unit suite needs.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

/**
 * Backing store for the shims in tests/stubs/wp-functions.php.
 *
 * The unit suite boots no WordPress, so the classes under test talk to this
 * instead. It mirrors the two behaviours the cache depends on: transients live
 * in the options table alongside a `_transient_timeout_` row, and object cache
 * entries carry their own expiry.
 *
 * @since 0.1.0
 */
final class FakeWordPress {

	/**
	 * Whether wp_using_ext_object_cache() should report a persistent backend.
	 *
	 * @var bool
	 */
	public static bool $ext_object_cache = false;

	/**
	 * Object cache entries: group => key => array{value: mixed, expires: int}.
	 *
	 * @var array<string, array<string, array{value: mixed, expires: int}>>
	 */
	public static array $object_cache = array();

	/**
	 * Option rows, including the transient rows WordPress keeps here.
	 *
	 * @var array<string, mixed>
	 */
	public static array $options = array();

	/**
	 * Whether is_admin() should report an admin request.
	 *
	 * @var bool
	 */
	public static bool $is_admin = false;

	/**
	 * Blocks recorded by the register_block_type() shim, keyed by metadata path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $blocks = array();

	/**
	 * User meta the get_user_meta() shim serves: user id => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $user_meta = array();

	/**
	 * Timestamps wp_next_scheduled() should report, keyed by hook.
	 *
	 * @var array<string, int>
	 */
	public static array $scheduled = array();

	/**
	 * Screen id get_current_screen() should report, empty for none.
	 *
	 * @var string
	 */
	public static string $current_screen = '';

	/**
	 * Settings registered through the register_setting() shim.
	 *
	 * @var list<array{group: string, option: string, args: array<string, mixed>}>
	 */
	public static array $registered_settings = array();

	/**
	 * Nonces the check_admin_referer() shim should accept. When empty, any
	 * nonce passes — a test asserting the check itself sets this.
	 *
	 * @var list<string>
	 */
	public static array $valid_referers = array();

	/**
	 * Options written with autoload explicitly disabled, as a set.
	 *
	 * @var array<string, bool>
	 */
	public static array $non_autoloaded_options = array();

	/**
	 * Hooks registered through add_action(): hook => list of callbacks.
	 *
	 * @var array<string, list<array{callback: mixed, priority: int}>>
	 */
	public static array $actions = array();

	/**
	 * Filter callbacks registered through add_filter(): hook => list of callables.
	 *
	 * @var array<string, list<callable>>
	 */
	public static array $filters = array();

	/**
	 * Fake orders the wc_get_orders() and wc_get_order() shims serve, keyed by id.
	 *
	 * @var array<int, \WC_Order>
	 */
	public static array $orders = array();

	/**
	 * Theme template overrides the locate_template() shim serves: name => path.
	 *
	 * @var array<string, string>
	 */
	public static array $theme_templates = array();

	/**
	 * Whether is_account_page() should report the My Account page.
	 *
	 * @var bool
	 */
	public static bool $is_account_page = false;

	/**
	 * Whether is_ssl() should report a TLS request.
	 *
	 * @var bool
	 */
	public static bool $is_ssl = false;

	/**
	 * Query vars the get_query_var() shim serves.
	 *
	 * @var array<string, mixed>
	 */
	public static array $query_vars = array();

	/**
	 * Shortcodes recorded by the add_shortcode() shim, keyed by tag.
	 *
	 * @var array<string, mixed>
	 */
	public static array $shortcodes = array();

	/**
	 * WooCommerce endpoints is_wc_endpoint_url() should report as current.
	 *
	 * @var list<string>
	 */
	public static array $endpoints = array();

	/**
	 * Post that get_post() should return.
	 *
	 * @var \WP_Post|null
	 */
	public static ?\WP_Post $post = null;

	/**
	 * User id get_current_user_id() should return.
	 *
	 * @var int
	 */
	public static int $current_user_id = 0;

	/**
	 * Page id wc_get_page_id( 'myaccount' ) should return.
	 *
	 * @var int
	 */
	public static int $account_page_id = 0;

	/**
	 * Post meta the get_post_meta() shim serves: post id => key => value.
	 *
	 * @var array<int, array<string, string>>
	 */
	public static array $post_meta = array();

	/**
	 * Post content the get_post_field() shim serves: post id => content.
	 *
	 * @var array<int, string>
	 */
	public static array $post_content = array();

	/**
	 * Every TTL passed to set_transient(): name => list of seconds.
	 *
	 * @var array<string, list<int>>
	 */
	public static array $transient_writes = array();

	/**
	 * Capabilities the current user should report having, for current_user_can().
	 *
	 * @var list<string>
	 */
	public static array $current_user_capabilities = array();

	/**
	 * REST routes recorded by the register_rest_route() shim.
	 *
	 * @var list<array{namespace: string, route: string, args: array<string, mixed>}>
	 */
	public static array $rest_routes = array();

	/**
	 * Styles recorded by the wp_enqueue_style() shim, keyed by handle.
	 *
	 * @var array<string, array{src: string, deps: array<int, string>, ver: mixed}>
	 */
	public static array $enqueued_styles = array();

	/**
	 * Extra style data recorded by the wp_style_add_data() shim, keyed by handle.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $style_data = array();

	/**
	 * Scripts recorded by the wp_enqueue_script() shim, keyed by handle.
	 *
	 * @var array<string, array{src: string, deps: array<int, string>, ver: mixed, in_footer: bool}>
	 */
	public static array $enqueued_scripts = array();

	/**
	 * Data recorded by the wp_localize_script() shim: handle => object name => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public static array $localized_scripts = array();

	/**
	 * Calls recorded by the wc_get_logger() shim's fake logger.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public static array $logged = array();

	/**
	 * Redirects recorded by the wp_safe_redirect() shim.
	 *
	 * @var list<array{location: string, status: int}>
	 */
	public static array $redirects = array();

	/**
	 * Submenu pages recorded by the add_submenu_page() shim.
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $submenus = array();

	/**
	 * Metaboxes recorded by the add_meta_box() shim.
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $meta_boxes = array();

	/**
	 * Fake users the get_userdata() shim serves, keyed by id.
	 *
	 * @var array<int, \WP_User>
	 */
	public static array $users = array();

	/**
	 * Order ids passed to the wc_increase_stock_levels() shim, in call order.
	 *
	 * @var list<int>
	 */
	public static array $restocked_orders = array();

	/**
	 * Calls recorded by the wc_create_refund() spy. Must stay zero for the
	 * lifetime of every test — see CancelTest's assertion.
	 *
	 * @var int
	 */
	public static int $refund_calls = 0;

	/**
	 * Locale get_locale() reports when no switch_to_locale() is in effect.
	 *
	 * @var string
	 */
	public static string $site_locale = 'en_US';

	/**
	 * User locales the get_user_locale() shim serves, keyed by user id.
	 *
	 * @var array<int, string>
	 */
	public static array $user_locales = array();

	/**
	 * Fake products the wc_get_product() shim serves, keyed by id.
	 *
	 * @var array<int, \WC_Product>
	 */
	public static array $products = array();

	/**
	 * Currency get_woocommerce_currency() reports.
	 *
	 * @var string
	 */
	public static string $currency = 'USD';

	/**
	 * Meta keys meta_is_product_attribute() should recognise.
	 *
	 * @var list<string>
	 */
	public static array $custom_attributes = array();

	/**
	 * Hooks did_action() should report as already fired.
	 *
	 * @var list<string>
	 */
	public static array $fired_actions = array();

	/**
	 * Every remove_action() call, whether or not it removed anything.
	 *
	 * @var list<array{hook: string, callback: mixed}>
	 */
	public static array $removed_actions = array();

	/**
	 * Emails recorded by the WC_Email stub's send() rather than delivered.
	 *
	 * @var list<array{id: string, to: string, subject: string, message: string}>
	 */
	public static array $sent_emails = array();

	/**
	 * Calls recorded by the wc_get_template() shim, in call order.
	 *
	 * @var list<array{name: string, args: array<string, mixed>}>
	 */
	public static array $rendered_templates = array();

	/**
	 * Locales pushed by switch_to_locale(), most recent last — mirrors
	 * WP_Locale_Switcher's own stack closely enough for AbstractEmail's and
	 * LocaleResolver's tests: get_locale() reads the top, restore_current_locale()
	 * pops it.
	 *
	 * @var list<string>
	 */
	public static array $locale_stack = array();

	/**
	 * Clears all state between tests.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ext_object_cache          = false;
		self::$object_cache              = array();
		self::$options                   = array();
		self::$non_autoloaded_options    = array();
		self::$is_admin                  = false;
		self::$blocks                    = array();
		self::$user_meta                 = array();
		self::$scheduled                 = array();
		self::$current_screen            = '';
		self::$registered_settings       = array();
		self::$valid_referers            = array();
		self::$actions                   = array();
		self::$filters                   = array();
		self::$transient_writes          = array();
		self::$orders                    = array();
		self::$theme_templates           = array();
		self::$is_account_page           = false;
		self::$is_ssl                    = false;
		self::$query_vars                = array();
		self::$shortcodes                = array();
		self::$endpoints                 = array();
		self::$post                      = null;
		self::$current_user_id           = 0;
		self::$account_page_id           = 0;
		self::$post_meta                 = array();
		self::$post_content              = array();
		self::$current_user_capabilities = array();
		self::$rest_routes               = array();
		self::$enqueued_styles           = array();
		self::$style_data                = array();
		self::$enqueued_scripts          = array();
		self::$localized_scripts         = array();
		self::$logged                    = array();
		self::$redirects                 = array();
		self::$submenus                  = array();
		self::$meta_boxes                = array();
		self::$users                     = array();
		self::$restocked_orders          = array();
		self::$refund_calls              = 0;
		self::$site_locale               = 'en_US';
		self::$user_locales              = array();
		self::$locale_stack              = array();
		self::$sent_emails               = array();
		self::$rendered_templates        = array();
		self::$products                  = array();
		self::$currency                  = 'USD';
		self::$custom_attributes         = array();
		self::$fired_actions             = array();
		self::$removed_actions           = array();
	}

	/**
	 * Transient names currently stored, without the WordPress prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public static function transient_names(): array {
		$names = array();

		foreach ( array_keys( self::$options ) as $option ) {
			if ( str_starts_with( $option, '_transient_' ) && ! str_starts_with( $option, '_transient_timeout_' ) ) {
				$names[] = substr( $option, strlen( '_transient_' ) );
			}
		}

		return $names;
	}

	/**
	 * Object cache keys currently stored in a group.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Cache group.
	 * @return list<string>
	 */
	public static function object_cache_keys( string $group ): array {
		return array_keys( self::$object_cache[ $group ] ?? array() );
	}
}
