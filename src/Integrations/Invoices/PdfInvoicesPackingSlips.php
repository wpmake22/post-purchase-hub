<?php
/**
 * Adapter for PDF Invoices & Packing Slips for WooCommerce (WP Overnight).
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Invoices;

/**
 * Surfaces the invoice this plugin has already generated for an order.
 *
 * The one invoice plugin this milestone ships an adapter for, on install base
 * (100k+) and because its customer-facing link is publicly documented — see
 * docs/MILESTONE-PROMPTS.md M13 and the report accompanying it for why no
 * second adapter shipped rather than one built on a guessed URL shape.
 *
 * Everything here is read through that plugin's own public surface, verified
 * against its current source rather than assumed:
 *
 * - `wcpdf_get_document( 'invoice', $order )` returns its document object
 *   (`wpo-ips-functions.php`), and `OrderDocument::exists()` reports whether an
 *   invoice has actually been generated for this order. An installed plugin
 *   with no document yet is the normal case and yields no link.
 * - The document object exposes no URL of its own, so the URL comes from the
 *   plugin's documented `[wcpdf_document_link]` shortcode, which builds it
 *   with that plugin's own access-key logic. Constructing the
 *   `admin-ajax.php?action=generate_wpo_wcpdf` URL here instead would mean
 *   owning their auth scheme, and getting it wrong is a broken link on a
 *   customer's order page.
 *
 * Every step is guarded, and any doubt resolves to "no source": a missing
 * function, a missing shortcode, output that is not a URL, or a throw from
 * inside the other plugin all return null.
 *
 * @since 0.13.0
 */
final class PdfInvoicesPackingSlips implements InvoiceProvider {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	public const ID = 'wpo-wcpdf';

	/**
	 * The function this adapter reads documents through.
	 *
	 * @var string
	 */
	private const DOCUMENT_FUNCTION = 'wcpdf_get_document';

	/**
	 * The shortcode this adapter reads the URL from.
	 *
	 * @var string
	 */
	private const LINK_SHORTCODE = 'wcpdf_document_link';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'PDF Invoices & Packing Slips for WooCommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return function_exists( self::DOCUMENT_FUNCTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order to find an invoice for.
	 * @return string|null
	 */
	public function url_for( \WC_Order $order ): ?string {
		if ( ! $this->is_active() || ! $this->has_invoice( $order ) ) {
			return null;
		}

		if ( ! function_exists( 'shortcode_exists' ) || ! shortcode_exists( self::LINK_SHORTCODE ) ) {
			return null;
		}

		$shortcode = sprintf( '[%s order_id="%d" document_type="invoice"]', self::LINK_SHORTCODE, $order->get_id() );

		try {
			$output = (string) do_shortcode( $shortcode );
		} catch ( \Throwable $e ) {
			unset( $e );

			return null;
		}

		return self::first_url_in( $output );
	}

	/**
	 * Whether that plugin has generated an invoice for this order.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to check.
	 * @return bool
	 */
	private function has_invoice( \WC_Order $order ): bool {
		try {
			// Guarded by is_active() in the one caller: this function belongs to
			// another plugin, and tests/stubs/invoice-plugins.php is what tells
			// static analysis it may exist at all.
			$document = wcpdf_get_document( 'invoice', $order );
		} catch ( \Throwable $e ) {
			// Another plugin's exception is not this plugin's to interpret; an
			// order page must render either way.
			unset( $e );

			return false;
		}

		if ( ! is_object( $document ) || ! is_callable( array( $document, 'exists' ) ) ) {
			return false;
		}

		try {
			return (bool) $document->exists();
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	/**
	 * Reads a URL out of shortcode output, whether it returned a bare URL or a link.
	 *
	 * Both shapes are accepted because which one a given version returns is
	 * that plugin's choice, not ours; anything else is treated as no URL at
	 * all. Entities are decoded so the caller escapes a real URL at output
	 * rather than printing `&#038;` back at the customer.
	 *
	 * @since 0.13.0
	 *
	 * @param string $output Raw shortcode output.
	 * @return string|null
	 */
	private static function first_url_in( string $output ): ?string {
		$candidate = html_entity_decode( trim( $output ), ENT_QUOTES, 'UTF-8' );

		if ( 1 === preg_match( '/href=["\']([^"\']+)["\']/i', $candidate, $matches ) ) {
			$candidate = html_entity_decode( trim( $matches[1] ), ENT_QUOTES, 'UTF-8' );
		}

		if ( '' === $candidate || false === filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
			return null;
		}

		return esc_url_raw( $candidate );
	}
}
