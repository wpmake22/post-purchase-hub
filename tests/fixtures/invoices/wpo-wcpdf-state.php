<?php
/**
 * Switchboard for the PDF Invoices & Packing Slips fixture.
 *
 * PHP functions cannot be undefined once declared, so what a test varies about
 * the fixture lives here rather than in whether its API exists.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Named for the plugin it stands in for, alongside this repository's other test doubles.

if ( ! class_exists( 'PPH_Fixture_Wpo_Wcpdf' ) ) {
	/**
	 * What the fixture reports, and what the adapter asked it.
	 */
	final class PPH_Fixture_Wpo_Wcpdf {

		/**
		 * The link output the document-link shortcode returns by default.
		 *
		 * @var string
		 */
		public const DEFAULT_LINK = 'https://shop.test/?action=generate_wpo_wcpdf&document_type=invoice&order_ids=4001&access_key=abc123';

		/**
		 * Whether a document exists for the order the adapter asks about.
		 *
		 * @var bool
		 */
		public static bool $document_exists = true;

		/**
		 * What the document-link shortcode returns.
		 *
		 * @var string
		 */
		public static string $link_output = self::DEFAULT_LINK;

		/**
		 * Whether wcpdf_get_document() should throw, as that plugin's own error
		 * handling can.
		 *
		 * @var bool
		 */
		public static bool $throws = false;

		/**
		 * Attributes the shortcode was last called with.
		 *
		 * @var array<string, string>
		 */
		public static array $last_atts = array();

		/**
		 * Restores the fixture's defaults.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$document_exists = true;
			self::$link_output     = self::DEFAULT_LINK;
			self::$throws          = false;
			self::$last_atts       = array();
		}
	}
}
