<?php
/**
 * Hand-off from WooCommerce's login form to this plugin's guest order view.
 *
 * Deliberately one line, and deliberately not the template a theme overrides —
 * `templates/myaccount/guest-order.php` is that one.
 *
 * This file exists because `wc_get_template()` extracts the arguments its
 * caller passed, and the caller substituted here is WooCommerce rendering
 * `myaccount/form-login.php` with none. There is therefore no way to hand a
 * view model in through the filter, so the render is handed back to
 * Frontend\GuestOrderView, which already holds the authorised order.
 *
 * @package PostPurchaseHub
 * @version 0.11.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders the order a guest's signed link authorised them to see.
 *
 * @since 0.11.0
 */
do_action( 'pph_render_guest_order' );
