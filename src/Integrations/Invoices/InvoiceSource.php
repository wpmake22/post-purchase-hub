<?php
/**
 * Where an order's invoice can be read from.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Invoices;

/**
 * One resolved place a customer can get something invoice-shaped for an order.
 *
 * Two kinds, and the distinction matters to the customer rather than to this
 * class: `KIND_DOCUMENT` is a real invoice another plugin has already
 * generated, and `KIND_PRINT_VIEW` is the order's own details page — the
 * fallback docs/SPEC.md asks for, which is not an invoice and is never
 * labelled as one.
 *
 * Pure data, with no knowledge of which plugin produced it beyond the
 * provider id it carries for diagnostics.
 *
 * @since 0.13.0
 */
final class InvoiceSource {

	/**
	 * A document another plugin generated.
	 *
	 * @var string
	 */
	public const KIND_DOCUMENT = 'document';

	/**
	 * The order's own details page, printable by the browser.
	 *
	 * @var string
	 */
	public const KIND_PRINT_VIEW = 'print_view';

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param string $kind     One of the KIND_* constants.
	 * @param string $url      Where the customer is sent.
	 * @param string $provider Provider id, or an empty string for the print-view fallback.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $url,
		public readonly string $provider = ''
	) {}

	/**
	 * Whether this is a real invoice document.
	 *
	 * @since 0.13.0
	 *
	 * @return bool
	 */
	public function is_document(): bool {
		return self::KIND_DOCUMENT === $this->kind;
	}

	/**
	 * The customer-facing label for this kind of source.
	 *
	 * A print view is never called an invoice: a merchant's tax-compliant
	 * invoice and a printed copy of an order page are different things, and
	 * telling a customer otherwise is the kind of claim that comes back as a
	 * support ticket.
	 *
	 * @since 0.13.0
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->is_document()
			? __( 'Download invoice', 'post-purchase-hub' )
			: __( 'View or print order', 'post-purchase-hub' );
	}
}
