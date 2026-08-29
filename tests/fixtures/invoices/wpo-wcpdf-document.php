<?php
/**
 * Document object for the PDF Invoices & Packing Slips fixture.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Named for the plugin it stands in for, alongside this repository's other test doubles.

if ( ! class_exists( 'WPMPHUB_Fixture_Wpo_Wcpdf_Document' ) ) {
	/**
	 * Stands in for WPO\IPS\Documents\OrderDocument, narrowed to the one method
	 * the adapter reads: `exists()`, which that class defines as whether a
	 * document date has been recorded.
	 */
	final class WPMPHUB_Fixture_Wpo_Wcpdf_Document {

		/**
		 * Whether this document has been generated.
		 *
		 * @return bool
		 */
		public function exists(): bool {
			return WPMPHUB_Fixture_Wpo_Wcpdf::$document_exists;
		}
	}
}
