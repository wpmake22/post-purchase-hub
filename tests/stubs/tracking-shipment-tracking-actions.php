<?php
/**
 * The WC_Shipment_Tracking_Actions name, for static analysis only.
 *
 * `Admin\HealthPanel` reports which tracking plugin a store has by asking
 * whether that plugin's own class exists. PHPStan knows only the WordPress and
 * WooCommerce stubs, so a guarded `class_exists()` on an optional dependency
 * reads to it as a check that can never be true. This file declares the name, and
 * is listed under `scanFiles` in phpstan.neon.dist beside the WordPress and
 * WooCommerce stubs.
 *
 * Nothing at runtime loads this file, and nothing in it is read: the panel asks
 * only whether the name exists, and never touches either plugin's data. It
 * lives under tests/ so the release build, which drops that directory, cannot
 * ship it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- These deliberately mirror other plugins' class names.

if ( ! class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
	/**
	 * The same extension's actions class, present in some versions.
	 */
	class WC_Shipment_Tracking_Actions {}
}
