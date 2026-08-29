<?php
/**
 * Estimated-delivery integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Plugin;
use PostPurchaseHub\Timeline\EstimatedDelivery;

/**
 * Exercises caching and resyncing against a real order.
 *
 * The business-day arithmetic itself is unit-tested exhaustively; this suite
 * only has to prove the parts that need a real WooCommerce order and real
 * hooks firing: that placing an order caches a range without anyone having
 * viewed it, and that a status change or a shipping-line change actually
 * rewrites that cache — all from write-triggered hooks, never from a read.
 *
 * @since 0.5.0
 *
 * @covers \PostPurchaseHub\Timeline\EstimatedDelivery
 */
final class EstimatedDeliveryTest extends \WP_UnitTestCase {

	/**
	 * A placement moment safely in the future, whatever "now" is when this runs.
	 *
	 * @var string
	 */
	private const FUTURE = '2099-06-02 09:00:00';

	/**
	 * Restores the plugin's settings between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		delete_option( 'wpmphub_settings' );

		parent::tear_down();
	}

	/**
	 * Creates an order with one shipping line, placed in the future.
	 *
	 * Placing the order this way — building the item in memory and saving
	 * once — is what fires `woocommerce_new_order_item` for the shipping line
	 * exactly as a real checkout does, which is the hook this milestone
	 * relies on to cache a range before anyone has viewed the order.
	 *
	 * @param string $method_id Shipping method id.
	 * @return \WC_Order
	 */
	private function order_with_shipping( string $method_id ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_date_created( self::FUTURE );

		$item = new \WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$item->set_method_title( $method_id );
		$item->set_total( 5 );
		$order->add_item( $item );

		$order->save();

		return $order;
	}

	/**
	 * The cached meta on a freshly reloaded copy of an order.
	 *
	 * @param int $order_id Order id.
	 * @return mixed
	 */
	private function cached_meta( int $order_id ) {
		$fresh = wc_get_order( $order_id );

		$this->assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh->get_meta( EstimatedDelivery::META_KEY, true );
	}

	/**
	 * Placing an order caches a range without anyone having read it.
	 *
	 * @return void
	 */
	public function test_placing_an_order_caches_a_range_via_crud(): void {
		update_option(
			'wpmphub_settings',
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 2,
						'max' => 4,
					),
				),
			)
		);

		$order = $this->order_with_shipping( 'flat_rate' );

		$cached = $this->cached_meta( $order->get_id() );

		$this->assertIsArray( $cached );
		$this->assertArrayHasKey( 'start', $cached );
		$this->assertArrayHasKey( 'end', $cached );

		$read = Plugin::instance()->estimated_delivery()->for_order( wc_get_order( $order->get_id() ) );
		$this->assertNotNull( $read );
	}

	/**
	 * A status change resyncs the cache to whatever settings are in effect now.
	 *
	 * @return void
	 */
	public function test_a_status_change_resyncs_the_cache(): void {
		update_option(
			'wpmphub_settings',
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order = $this->order_with_shipping( 'flat_rate' );
		$first = $this->cached_meta( $order->get_id() );
		$this->assertIsArray( $first );

		update_option(
			'wpmphub_settings',
			array(
				EstimatedDelivery::HANDLING_SETTING => 6,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate' => array(
						'min' => 6,
						'max' => 6,
					),
				),
			)
		);

		// The status transition this plugin already listens for, per M03.
		$order->set_status( 'processing' );
		$order->save();

		$second = $this->cached_meta( $order->get_id() );

		$this->assertIsArray( $second );
		$this->assertNotSame(
			$first['start'],
			$second['start'],
			'The transition must have resynced the cache against the settings now in effect.'
		);
	}

	/**
	 * A status change clears the cache when tracking data now exists.
	 *
	 * @return void
	 */
	public function test_a_status_change_clears_the_cache_once_tracking_appears(): void {
		update_option(
			'wpmphub_settings',
			array(
				EstimatedDelivery::TRANSIT_SETTING => array(
					'flat_rate' => array(
						'min' => 1,
						'max' => 1,
					),
				),
			)
		);

		$order = $this->order_with_shipping( 'flat_rate' );
		$this->assertIsArray( $this->cached_meta( $order->get_id() ) );

		add_filter( 'wpmphub_has_tracking_data', '__return_true' );

		$order->set_status( 'processing' );
		$order->save();

		remove_filter( 'wpmphub_has_tracking_data', '__return_true' );

		$this->assertSame( '', $this->cached_meta( $order->get_id() ) );
	}

	/**
	 * Replacing the shipping line resyncs the cache to the new method's config.
	 *
	 * @return void
	 */
	public function test_a_shipping_line_change_resyncs_the_cache(): void {
		update_option(
			'wpmphub_settings',
			array(
				EstimatedDelivery::HANDLING_SETTING => 1,
				EstimatedDelivery::TRANSIT_SETTING  => array(
					'flat_rate'    => array(
						'min' => 1,
						'max' => 1,
					),
					'local_pickup' => array(
						'min' => 8,
						'max' => 8,
					),
				),
			)
		);

		$order = $this->order_with_shipping( 'flat_rate' );
		$first = $this->cached_meta( $order->get_id() );
		$this->assertIsArray( $first );

		foreach ( $order->get_shipping_methods() as $existing ) {
			$order->remove_item( $existing->get_id() );
		}

		$new_item = new \WC_Order_Item_Shipping();
		$new_item->set_method_id( 'local_pickup' );
		$new_item->set_method_title( 'Local pickup' );
		$new_item->set_total( 0 );
		$order->add_item( $new_item );

		$order->save();

		$second = $this->cached_meta( $order->get_id() );

		$this->assertIsArray( $second );
		$this->assertNotSame( $first['start'], $second['start'] );
	}

	/**
	 * An order with no shipping line configured never has anything cached.
	 *
	 * @return void
	 */
	public function test_no_shipping_line_never_caches_an_estimate(): void {
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_date_created( self::FUTURE );
		$order->save();

		$this->assertSame( '', $this->cached_meta( $order->get_id() ) );
		$this->assertNull( Plugin::instance()->estimated_delivery()->for_order( wc_get_order( $order->get_id() ) ) );

		Plugin::instance()->estimated_delivery()->sync( wc_get_order( $order->get_id() ) );

		$this->assertSame( '', $this->cached_meta( $order->get_id() ), 'sync() must not save when there is nothing to cache.' );
	}
}
