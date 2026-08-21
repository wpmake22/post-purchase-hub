<?php
/**
 * HardExclusions unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Integrations\Compat;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Integrations\Compat\HardExclusions;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 3 ) . '/stubs/wp-functions.php';

/**
 * Covers the default exclusion lists and their filters. Neither WooCommerce
 * Subscriptions nor Bookings is installed in this environment, so these tests
 * cover the slugs and the filter mechanism, not a live integration.
 *
 * @since 0.7.0
 *
 * @covers \PostPurchaseHub\Integrations\Compat\HardExclusions
 */
final class HardExclusionsTest extends TestCase {

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
	 * The subscription order type is excluded by default.
	 *
	 * @return void
	 */
	public function test_order_types_excludes_shop_subscription_by_default(): void {
		$this->assertContains( 'shop_subscription', HardExclusions::order_types() );
	}

	/**
	 * Subscription and booking product types are excluded by default.
	 *
	 * @return void
	 */
	public function test_product_types_excludes_subscriptions_and_bookings_by_default(): void {
		$product_types = HardExclusions::product_types();

		$this->assertContains( 'subscription', $product_types );
		$this->assertContains( 'variable-subscription', $product_types );
		$this->assertContains( 'booking', $product_types );
	}

	/**
	 * The order-type exclusion is filterable, including down to nothing.
	 *
	 * @return void
	 */
	public function test_order_types_can_be_filtered_to_empty(): void {
		add_filter( 'pph_compat_excluded_order_types', static fn (): array => array() );

		$this->assertSame( array(), HardExclusions::order_types() );
	}

	/**
	 * The product-type exclusion is filterable, including down to nothing.
	 *
	 * @return void
	 */
	public function test_product_types_can_be_filtered_to_empty(): void {
		add_filter( 'pph_compat_excluded_product_types', static fn (): array => array() );

		$this->assertSame( array(), HardExclusions::product_types() );
	}
}
