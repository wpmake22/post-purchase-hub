<?php
/**
 * The invoice-access action.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Invoices\InvoiceSource;

/**
 * A link to the invoice a store already has — never one this plugin made.
 *
 * `Integrations\Invoices\Detector` answers where an invoice can be read from;
 * this class decides when a customer is offered the link, which is the part
 * with a judgement in it:
 *
 * - A real document (an invoice plugin has generated one) is offered in both
 *   contexts.
 * - With no document, the fallback docs/SPEC.md asks for ("else Woo print
 *   view") is offered in the orders *list* only, pointing at the order's own
 *   details page — the page a customer prints when no invoice plugin exists,
 *   since WooCommerce core ships no print view of its own (checked against Woo
 *   11.0.1). On the details page that same link would point at the page the
 *   customer is already reading, which is the dead button CLAUDE.md hard rule
 *   19 rules out, so there it is absent.
 *
 * The result is that M13's acceptance criterion holds in the place it was
 * written about — no invoice button, no broken link and no placeholder on an
 * order page with no invoice — while the fallback still exists where it is
 * worth something.
 *
 * @since 0.13.0
 */
final class Invoice {

	/**
	 * Action id.
	 *
	 * @var string
	 */
	public const ID = 'invoice';

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param EligibilityResolver $eligibility Eligibility engine, for the `pph_action_eligibility` filter.
	 * @param Detector            $detector    Where an order's invoice is read from.
	 */
	public function __construct( private EligibilityResolver $eligibility, private Detector $detector ) {}

	/**
	 * Registers this action against the registry.
	 *
	 * @since 0.13.0
	 *
	 * @param ActionRegistry $registry Registry to register against.
	 * @return void
	 */
	public function register( ActionRegistry $registry ): void {
		$registry->register(
			self::ID,
			self::label(),
			array( 'list', 'detail' ),
			\Closure::fromCallable( array( $this, 'resolve' ) )
		);
	}

	/**
	 * Render payload for one order and context, or null when there is nothing
	 * to link to.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order   Order to resolve against.
	 * @param string    $context Context being rendered: the fallback differs between them.
	 * @return array<string, string>|null
	 */
	public function resolve( \WC_Order $order, string $context ): ?array {
		if ( ! $this->check( $order )->eligible ) {
			return null;
		}

		$source = $this->source_for( $order, $context );

		if ( null === $source ) {
			return null;
		}

		return array(
			'name' => $source->label(),
			'url'  => $source->url,
		);
	}

	/**
	 * Whether this order may be offered an invoice link at all.
	 *
	 * Constrains nothing by itself: an invoice exists or it does not, and no
	 * status, age or product type changes that. The rule is still evaluated
	 * through `EligibilityResolver` so a merchant can hang a restriction off
	 * `pph_action_eligibility` here exactly as they can on cancel and reorder.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function check( \WC_Order $order ): EligibilityResult {
		return $this->eligibility->resolve( self::ID, $order, new EligibilityRule() );
	}

	/**
	 * The source this order's invoice link should point at, in one context.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order   Order to resolve.
	 * @param string    $context Context being rendered.
	 * @return InvoiceSource|null
	 */
	public function source_for( \WC_Order $order, string $context ): ?InvoiceSource {
		$source = $this->detector->source_for( $order );

		if ( null !== $source ) {
			return $source;
		}

		if ( 'list' !== $context || ! self::print_fallback_enabled( $order ) ) {
			return null;
		}

		$url = $order->get_view_order_url();

		return '' === $url ? null : new InvoiceSource( InvoiceSource::KIND_PRINT_VIEW, $url );
	}

	/**
	 * Whether the print-view fallback is offered when no invoice plugin is installed.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order being resolved.
	 * @return bool
	 */
	private static function print_fallback_enabled( \WC_Order $order ): bool {
		/**
		 * Filters whether the orders list offers the order's own page as a
		 * printable stand-in when no invoice plugin has a document for it.
		 *
		 * A store that considers anything invoice-shaped a matter for its
		 * accountant turns this off and gets no link at all until an invoice
		 * plugin provides one.
		 *
		 * @since 0.13.0
		 *
		 * @param bool      $enabled Whether to offer the fallback. Default true.
		 * @param \WC_Order $order   Order being resolved.
		 */
		return (bool) apply_filters( 'pph_invoice_print_fallback', true, $order );
	}

	/**
	 * The registry label, used where no source has been resolved yet.
	 *
	 * The rendered label comes from the resolved `InvoiceSource` instead, so a
	 * print view is never announced as an invoice.
	 *
	 * @since 0.13.0
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Invoice', 'post-purchase-hub' );
	}
}
