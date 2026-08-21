<?php
/**
 * Fixture standing in for PDF Invoices & Packing Slips for WooCommerce.
 *
 * Declares only the public surface
 * `Integrations\Invoices\PdfInvoicesPackingSlips` reads, in the shapes that
 * plugin's own source uses — a `wcpdf_get_document()` function returning a
 * document whose `exists()` reports whether an invoice has been generated
 * (wpo-ips-functions.php, Documents/OrderDocument.php), and the documented
 * `[wcpdf_document_link]` shortcode returning the download URL.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

require_once __DIR__ . '/wpo-wcpdf-state.php';
require_once __DIR__ . '/wpo-wcpdf-document.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This fixture must carry the third-party function name the adapter reads.

if ( ! function_exists( 'wcpdf_get_document' ) ) {
	/**
	 * Returns the fixture's document object.
	 *
	 * @param string $document_type Document type requested.
	 * @param mixed  $order         Order the document belongs to.
	 * @param bool   $init          Whether to initialise, unused.
	 * @return PPH_Fixture_Wpo_Wcpdf_Document
	 * @throws RuntimeException When the fixture is set to throw.
	 */
	function wcpdf_get_document( string $document_type, $order, bool $init = false ): PPH_Fixture_Wpo_Wcpdf_Document {
		unset( $document_type, $order, $init );

		if ( PPH_Fixture_Wpo_Wcpdf::$throws ) {
			throw new RuntimeException( 'Fixture failure inside the invoice plugin.' );
		}

		return new PPH_Fixture_Wpo_Wcpdf_Document();
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

if ( ! function_exists( 'pph_fixture_register_wcpdf_shortcode' ) ) {
	/**
	 * Registers the fixture's document-link shortcode.
	 *
	 * @return void
	 */
	function pph_fixture_register_wcpdf_shortcode(): void {
		add_shortcode(
			'wcpdf_document_link',
			static function ( $atts = array() ): string {
				PPH_Fixture_Wpo_Wcpdf::$last_atts = is_array( $atts ) ? $atts : array();

				return PPH_Fixture_Wpo_Wcpdf::$link_output;
			}
		);
	}
}
