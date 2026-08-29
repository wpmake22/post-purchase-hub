<?php
/**
 * Order detail, replacing WooCommerce's myaccount/view-order.php.
 *
 * Used only when replacement mode is on. WooCommerce's own order details table
 * is kept — it renders line items, totals and addresses that this plugin has no
 * business reimplementing — and the timeline is placed above it.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/myaccount/view-order.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var \WC_Order $order    Order being viewed, supplied by WooCommerce.
 * @var int       $order_id Order id, supplied by WooCommerce.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders one order's timeline.
 *
 * @since 0.4.0
 *
 * @param \WC_Order $order Order to describe.
 */
do_action( 'wpmphub_render_order_detail', $order ?? null );

/**
 * Renders the merchant's notes to this customer.
 *
 * WooCommerce's own template lists these as "Order updates". This one replaces
 * that template, so it has to carry them.
 *
 * @since 0.4.1
 *
 * @param \WC_Order $order Order whose notes to show.
 */
do_action( 'wpmphub_render_order_notes', $order ?? null );

/**
 * Fires after this plugin's order detail heading.
 *
 * Mirrors WooCommerce's own `woocommerce_view_order`, so an integration hooked
 * there keeps rendering in replacement mode. Core's order details table is
 * attached to it, which is what draws the items and totals below.
 *
 * @since 0.4.0
 *
 * @param int $order_id Order id.
 */
do_action( 'woocommerce_view_order', isset( $order_id ) ? (int) $order_id : 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately re-fires WooCommerce's own hook: this file stands in for the core template that would have fired it.
