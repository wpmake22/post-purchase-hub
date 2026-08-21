<?php
/**
 * Invoice-source adapter contract.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Invoices;

/**
 * One installed invoice plugin, as far as this plugin needs to know it.
 *
 * Adapters read; they never write, never generate a PDF and never repair
 * another plugin's data (CLAUDE.md hard rules 8 and 9). An adapter answers two
 * questions and nothing else: is your plugin here, and do you already have an
 * invoice for this order that the customer may download.
 *
 * `url_for()` returning null is the normal case, not an error: an invoice
 * plugin that is installed but has not generated a document for this order yet
 * has no URL to give, and inventing one would put a broken link on a
 * customer's order page (docs/MILESTONE-PROMPTS.md M13's first acceptance
 * criterion).
 *
 * @since 0.13.0
 */
interface InvoiceProvider {

	/**
	 * Stable id for logs, caching and the admin health panel.
	 *
	 * @since 0.13.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The plugin's own name, for a merchant reading a diagnostic.
	 *
	 * Deliberately not translated: it is a third party's product name.
	 *
	 * @since 0.13.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether this plugin is installed and exposing the API this adapter uses.
	 *
	 * @since 0.13.0
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * The customer-facing download URL for this order's invoice.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to find an invoice for.
	 * @return string|null Null when this plugin has no invoice for this order.
	 */
	public function url_for( \WC_Order $order ): ?string;
}
