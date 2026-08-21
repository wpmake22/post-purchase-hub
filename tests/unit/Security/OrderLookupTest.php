<?php
/**
 * OrderLookup unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the matching half of docs/MILESTONE-PROMPTS.md M11: both fields must
 * match, and sequential order-id probing gains nothing.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Security\OrderLookup
 */
final class OrderLookupTest extends TestCase {

	/**
	 * Matcher under test.
	 *
	 * @var OrderLookup
	 */
	private OrderLookup $lookup;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->lookup = new OrderLookup();
	}

	/**
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int    $id     Order id.
	 * @param string $email  Billing email.
	 * @param string $number Customer-facing order number, when it differs from the id.
	 * @return \WC_Order
	 */
	private function order( int $id, string $email = 'jane@example.com', string $number = '' ): \WC_Order {
		$order = new \WC_Order( $id, 'processing' );
		$order->set_billing_email( $email );

		if ( '' !== $number ) {
			$order->set_order_number( $number );
		}

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * The matching pair resolves.
	 *
	 * @return void
	 */
	public function test_a_matching_pair_returns_the_order(): void {
		$order = $this->order( 42 );

		$this->assertSame( $order, $this->lookup->find( '42', 'jane@example.com' ) );
	}

	/**
	 * The `#` customers copy out of their confirmation email is tolerated.
	 *
	 * @return void
	 */
	public function test_it_tolerates_a_leading_hash_and_surrounding_space(): void {
		$order = $this->order( 42 );

		$this->assertSame( $order, $this->lookup->find( '  #42 ', 'jane@example.com' ) );
	}

	/**
	 * Alias spellings of the same mailbox still match.
	 *
	 * @return void
	 */
	public function test_it_matches_an_alias_spelling_of_the_billing_address(): void {
		$order = $this->order( 42, 'jane.doe@example.com' );

		$this->assertSame( $order, $this->lookup->find( '42', 'Jane.Doe+shop@Example.com' ) );
	}

	/**
	 * A different mailbox does not.
	 *
	 * @return void
	 */
	public function test_the_wrong_email_matches_nothing(): void {
		$this->order( 42, 'jane@example.com' );

		$this->assertNull( $this->lookup->find( '42', 'john@example.com' ) );
	}

	/**
	 * A number no order carries matches nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_order_number_matches_nothing(): void {
		$this->assertNull( $this->lookup->find( '999', 'jane@example.com' ) );
	}

	/**
	 * An order with no billing address on it cannot be looked up, rather than
	 * being looked up by submitting an empty string.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_billing_email_matches_nothing(): void {
		$this->order( 42, '' );

		$this->assertNull( $this->lookup->find( '42', '' ) );
	}

	/**
	 * Sequential id probing gains nothing on a store whose numbers are not ids:
	 * the id resolves an order, and the order refuses it because the number the
	 * customer was actually given is different.
	 *
	 * @return void
	 */
	public function test_a_raw_id_is_refused_where_the_order_number_differs(): void {
		$this->order( 42, 'jane@example.com', 'INV-2026-0042' );

		$this->assertNull( $this->lookup->find( '42', 'jane@example.com' ) );
	}

	/**
	 * The same store, looked up by the number on the customer's invoice, works
	 * once a numbering plugin answers WooCommerce's own filter.
	 *
	 * @return void
	 */
	public function test_a_custom_order_number_resolves_through_woocommerce_s_filter(): void {
		$order = $this->order( 42, 'jane@example.com', 'INV-2026-0042' );

		add_filter(
			'woocommerce_shortcode_order_tracking_order_id',
			static function ( $number ): int {
				return 'INV-2026-0042' === $number ? 42 : 0;
			}
		);

		$this->assertSame( $order, $this->lookup->find( 'INV-2026-0042', 'jane@example.com' ) );
	}

	/**
	 * This plugin's own filter can make a number unresolvable.
	 *
	 * @return void
	 */
	public function test_the_plugin_filter_can_refuse_a_number(): void {
		$this->order( 42 );

		add_filter(
			'pph_lookup_order_id',
			static function (): int {
				return 0;
			}
		);

		$this->assertNull( $this->lookup->find( '42', 'jane@example.com' ) );
	}

	/**
	 * Nothing is read from an over-long submission.
	 *
	 * @return void
	 */
	public function test_an_over_long_order_number_matches_nothing(): void {
		$this->order( 42 );

		$this->assertNull( $this->lookup->find( str_repeat( '4', OrderLookup::MAX_NUMBER_LENGTH + 1 ), 'jane@example.com' ) );
	}

	/**
	 * An empty submission matches nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_order_number_matches_nothing(): void {
		$this->order( 42 );

		$this->assertNull( $this->lookup->find( '   ', 'jane@example.com' ) );
	}
}
