<?php
/**
 * WC_DateTime stub for the unit suite.
 *
 * The unit suite boots no WooCommerce, so the classes our timeline code
 * type-hints are defined here. Every declaration is guarded, so loading this
 * where the real WooCommerce exists is a no-op.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- These stubs must carry the WooCommerce names they replace.

if ( ! class_exists( 'WC_DateTime' ) ) {
	/**
	 * Stand-in for WooCommerce's DateTime subclass.
	 */
	class WC_DateTime extends DateTime {}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

require_once __DIR__ . '/wc-settings-api.php';
require_once __DIR__ . '/wc-email.php';
require_once __DIR__ . '/wc-order-item-shipping.php';
require_once __DIR__ . '/wc-product.php';
require_once __DIR__ . '/wc-order-item-product.php';
require_once __DIR__ . '/wc-order.php';
