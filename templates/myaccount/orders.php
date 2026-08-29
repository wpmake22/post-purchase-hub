<?php
/**
 * Orders list, replacing WooCommerce's myaccount/orders.php.
 *
 * Used only when replacement mode is on. WooCommerce includes this file with
 * its own variables in scope, so this is the one place where its contract and
 * ours meet — and it does nothing with them but hand them over. The markup
 * lives in partials/orders-list.php, which is where a theme should override it.
 *
 * Override by copying this file to yourtheme/wpmake-post-purchase-hub/myaccount/orders.php.
 *
 * @package PostPurchaseHub
 * @version 0.4.0
 *
 * @var object $customer_orders Paginated order result supplied by WooCommerce.
 * @var bool   $has_orders      Whether the customer has any orders.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders the customer's orders with their timelines.
 *
 * @since 0.4.0
 *
 * @param array<int, \WC_Order> $orders     Orders to render.
 * @param string                $empty_text Message shown when there are none.
 */
do_action(
	'wpmphub_render_orders_list',
	( isset( $has_orders ) && $has_orders && isset( $customer_orders->orders ) && is_array( $customer_orders->orders ) )
		? $customer_orders->orders
		: array(),
	__( 'No order has been made yet.', 'wpmake-post-purchase-hub' )
);
