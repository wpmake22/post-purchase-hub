<?php
/**
 * Invoice provider double.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Integrations\Invoices;

use PostPurchaseHub\Integrations\Invoices\InvoiceProvider;

/**
 * One invoice plugin's fixture: whether it is installed, whether it has a
 * document for the order, and how often the detector asked.
 *
 * The call counters are what make the caching assertions possible — "cached"
 * means "did not ask again", which is only observable from the provider's side.
 *
 * @since 0.13.0
 */
final class FakeInvoiceProvider implements InvoiceProvider {

	/**
	 * Times is_active() was called.
	 *
	 * @var int
	 */
	public int $active_calls = 0;

	/**
	 * Times url_for() was called.
	 *
	 * @var int
	 */
	public int $url_calls = 0;

	/**
	 * Constructor.
	 *
	 * @param string      $id     Provider id.
	 * @param bool        $active Whether this fixture reports itself installed.
	 * @param string|null $url    URL to report for any order, null for none.
	 */
	public function __construct(
		private string $id,
		public bool $active = true,
		private ?string $url = null
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Fixture ' . $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		++$this->active_calls;

		return $this->active;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order to find an invoice for, unused by the fixture.
	 * @return string|null
	 */
	public function url_for( \WC_Order $order ): ?string {
		unset( $order );

		++$this->url_calls;

		return $this->url;
	}
}
