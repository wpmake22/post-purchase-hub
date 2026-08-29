<?php
/**
 * Invoice-plugin detection.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Integrations\Invoices;

use PostPurchaseHub\Support\Cache;

/**
 * Finds the installed invoice plugin, once per twelve hours, and asks it for
 * one order's invoice URL.
 *
 * This plugin generates no PDFs and never will in v1 (docs/SPEC.md Phase 3:
 * "a PDF invoice generator looks like a two-hour job, is not" — jurisdictional
 * numbering, VAT fields and ~4MB of vendor code). It reads what a store's
 * existing invoice plugin has already produced, and where there is nothing to
 * read it says so rather than inventing a link.
 *
 * Two things are separated deliberately:
 *
 * - *Which plugin is installed* changes only when a plugin is activated or
 *   deactivated, so it is cached (`forget()` clears it, wired to those two
 *   hooks in `Plugin`). Detection walks class and function checks, which are
 *   cheap individually and pointless to repeat on every order row.
 * - *Whether this order has an invoice* is never cached: a merchant generating
 *   an invoice must not have to wait out a TTL before their customer can see
 *   it, and the answer costs one adapter call.
 *
 * @since 0.13.0
 */
final class Detector {

	/**
	 * Cache key holding the detected provider id.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'invoice_provider';

	/**
	 * Cached value meaning "looked, found nothing".
	 *
	 * `Cache` cannot round-trip a stored `false` through its transient
	 * backend, so "no provider" is stored as a sentinel rather than as a
	 * falsy value that would read as a cache miss forever.
	 *
	 * @var string
	 */
	private const NONE = 'none';

	/**
	 * How long a detection result stands.
	 *
	 * @var int
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Providers to try, in order.
	 *
	 * @var array<int, InvoiceProvider>
	 */
	private array $providers;

	/**
	 * Memoised detection for this request.
	 *
	 * @var InvoiceProvider|null
	 */
	private ?InvoiceProvider $detected = null;

	/**
	 * Whether detection has already run this request.
	 *
	 * @var bool
	 */
	private bool $resolved = false;

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param Cache                            $cache     Backing store for the detection result.
	 * @param array<int, InvoiceProvider>|null $providers Providers to try. Null uses the shipped list.
	 */
	public function __construct( private Cache $cache, ?array $providers = null ) {
		$this->providers = null === $providers ? self::default_providers() : array_values( $providers );
	}

	/**
	 * The adapters this plugin ships, filterable.
	 *
	 * The extension point for every invoice plugin core does not adapt to
	 * itself: a merchant, or Pro, adds an `InvoiceProvider` here rather than
	 * this class growing a list of plugins nobody verified against
	 * (docs/EDITIONS.md — core registers the point, editions fill it).
	 *
	 * @since 0.13.0
	 *
	 * @return array<int, InvoiceProvider>
	 */
	public static function default_providers(): array {
		/**
		 * Filters the invoice-plugin adapters this plugin tries, in order.
		 *
		 * @since 0.13.0
		 *
		 * @param array<int, InvoiceProvider> $providers Adapters to try.
		 */
		$providers = apply_filters( 'wpmphub_invoice_providers', array( new PdfInvoicesPackingSlips() ) );

		if ( ! is_array( $providers ) ) {
			return array( new PdfInvoicesPackingSlips() );
		}

		return array_values(
			array_filter(
				$providers,
				static function ( $provider ): bool {
					return $provider instanceof InvoiceProvider;
				}
			)
		);
	}

	/**
	 * The installed invoice plugin, if any.
	 *
	 * @since 0.13.0
	 *
	 * @return InvoiceProvider|null
	 */
	public function detect(): ?InvoiceProvider {
		if ( $this->resolved ) {
			return $this->detected;
		}

		$this->resolved = true;
		$this->detected = $this->cached_or_probed();

		return $this->detected;
	}

	/**
	 * Where this order's invoice can be read from, if anywhere.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to find an invoice for.
	 * @return InvoiceSource|null
	 */
	public function source_for( \WC_Order $order ): ?InvoiceSource {
		$provider = $this->detect();
		$source   = null;

		if ( null !== $provider ) {
			$url = $provider->url_for( $order );

			if ( is_string( $url ) && '' !== $url ) {
				$source = new InvoiceSource( InvoiceSource::KIND_DOCUMENT, $url, $provider->id() );
			}
		}

		/**
		 * Filters where an order's invoice is read from.
		 *
		 * The escape hatch for an invoice plugin this build ships no adapter
		 * for: return an `InvoiceSource` and the invoice action appears with
		 * that URL. Returning null removes it. Anything else is ignored.
		 *
		 * @since 0.13.0
		 *
		 * @param InvoiceSource|null $source Detected source, null when none was found.
		 * @param \WC_Order          $order  Order being resolved.
		 */
		$filtered = apply_filters( 'wpmphub_invoice_source', $source, $order );

		if ( null === $filtered ) {
			return null;
		}

		return $filtered instanceof InvoiceSource ? $filtered : $source;
	}

	/**
	 * Drops the cached detection.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->cache->delete( self::CACHE_KEY );

		$this->resolved = false;
		$this->detected = null;
	}

	/**
	 * Reads the cached detection, and probes only when the cache cannot answer.
	 *
	 * A cached "nothing installed" is trusted for its full TTL — that is the
	 * point of caching a negative result, and the activation and deactivation
	 * hooks in `Plugin` are what make it safe: installing an invoice plugin
	 * clears this before its next order page renders.
	 *
	 * A cached id whose adapter is no longer active is discarded and detection
	 * runs again, because trusting it is how a store ends up with an invoice
	 * button pointing at a plugin that is gone.
	 *
	 * @since 0.13.0
	 *
	 * @return InvoiceProvider|null
	 */
	private function cached_or_probed(): ?InvoiceProvider {
		$cached = $this->cache->get( self::CACHE_KEY );

		if ( ! is_string( $cached ) || '' === $cached ) {
			return $this->probe();
		}

		if ( self::NONE === $cached ) {
			return null;
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->id() === $cached && $provider->is_active() ) {
				return $provider;
			}
		}

		return $this->probe();
	}

	/**
	 * Runs detection and caches the outcome.
	 *
	 * @since 0.13.0
	 *
	 * @return InvoiceProvider|null
	 */
	private function probe(): ?InvoiceProvider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_active() ) {
				$this->cache->set( self::CACHE_KEY, $provider->id(), self::TTL );

				return $provider;
			}
		}

		$this->cache->set( self::CACHE_KEY, self::NONE, self::TTL );

		return null;
	}
}
