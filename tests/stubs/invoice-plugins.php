<?php
/**
 * Third-party invoice-plugin APIs static analysis cannot discover.
 *
 * `Integrations\Invoices\PdfInvoicesPackingSlips` reads one function that
 * belongs to another plugin, so PHPStan — which knows only the WordPress and
 * WooCommerce stubs — cannot tell a guarded call to an optional dependency
 * from a call to a function that does not exist. This file declares that
 * surface, in the shape its own source defines (wpo-ips-functions.php and
 * Documents/OrderDocument.php), and is listed under `scanFiles` in
 * phpstan.neon.dist alongside the WordPress and WooCommerce stubs.
 *
 * PHPStan reads the declarations without executing them, and nothing at
 * runtime ever loads this file: the unit suite loads
 * tests/fixtures/invoices/wpo-wcpdf.php instead, which is a working double
 * rather than a signature. It lives under tests/ so the release build, which
 * drops that directory, cannot ship it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- These deliberately mirror another plugin's public names.

if ( ! function_exists( 'wcpdf_get_document' ) ) {
	/**
	 * Returns an invoice document object for an order, or false.
	 *
	 * @param string $document_type Document type, e.g. `invoice`.
	 * @param mixed  $order         Order, or order id.
	 * @param bool   $init          Whether to initialise the document.
	 * @return object|false
	 */
	function wcpdf_get_document( string $document_type, $order, bool $init = false ) {
		unset( $document_type, $order, $init );

		return false;
	}
}
