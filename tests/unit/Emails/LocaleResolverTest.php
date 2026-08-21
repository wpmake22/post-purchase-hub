<?php
/**
 * LocaleResolver unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Emails\LocaleResolver;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the resolution order docblocked on `LocaleResolver::for_order()`:
 * order meta, then the customer's account locale, then the site default —
 * with the `pph_email_locale` filter able to override any of them.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\LocaleResolver
 */
final class LocaleResolverTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * With nothing else to go on, the site's own locale wins.
	 *
	 * @return void
	 */
	public function test_falls_back_to_the_site_locale(): void {
		FakeWordPress::$site_locale = 'de_DE';

		$order = new \WC_Order( 1 );

		$this->assertSame( 'de_DE', LocaleResolver::for_order( $order ) );
	}

	/**
	 * A registered customer's own account locale beats the site's.
	 *
	 * @return void
	 */
	public function test_prefers_the_customers_account_locale(): void {
		FakeWordPress::$site_locale      = 'en_US';
		FakeWordPress::$user_locales[42] = 'es_ES';

		$order = new \WC_Order( 1 );
		$order->set_customer_id( 42 );

		$this->assertSame( 'es_ES', LocaleResolver::for_order( $order ) );
	}

	/**
	 * The WPML/WooCommerce Multilingual order-language meta beats both the
	 * customer's account locale and the site's — it is what the customer
	 * actually checked out in, which a guest has no account locale to record.
	 *
	 * @return void
	 */
	public function test_prefers_the_wpml_language_order_meta(): void {
		FakeWordPress::$site_locale      = 'en_US';
		FakeWordPress::$user_locales[42] = 'es_ES';

		$order = new \WC_Order( 1 );
		$order->set_customer_id( 42 );
		$order->update_meta_data( 'wpml_language', 'fr_FR' );

		$this->assertSame( 'fr_FR', LocaleResolver::for_order( $order ) );
	}

	/**
	 * A guest order with no meta and no account falls back to the site locale.
	 *
	 * @return void
	 */
	public function test_guest_order_falls_back_to_the_site_locale(): void {
		FakeWordPress::$site_locale = 'it_IT';

		$order = new \WC_Order( 1 );

		$this->assertSame( 'it_IT', LocaleResolver::for_order( $order ) );
	}

	/**
	 * The pph_email_locale filter overrides every built-in guess.
	 *
	 * @return void
	 */
	public function test_filter_overrides_every_built_in_guess(): void {
		FakeWordPress::$site_locale = 'en_US';

		$order = new \WC_Order( 1 );
		$order->update_meta_data( 'wpml_language', 'fr_FR' );

		add_filter(
			'pph_email_locale',
			static function ( $locale, $filtered_order ) use ( $order ) {
				self::assertSame( $order, $filtered_order );

				return 'pt_BR';
			},
			10,
			2
		);

		$this->assertSame( 'pt_BR', LocaleResolver::for_order( $order ) );
	}
}
