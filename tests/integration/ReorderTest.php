<?php
/**
 * Reorder integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderOptions;
use PostPurchaseHub\Actions\ReorderLine;
use PostPurchaseHub\Actions\ReorderPlanner;
use PostPurchaseHub\Actions\WooCommerceCart;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Support\Logger;

/**
 * Exercises reorder against real products, a real order and WooCommerce's own
 * cart, under whichever order storage this leg of the matrix is running.
 *
 * The unit suite proves the branches; this proves the two things only real
 * WooCommerce can answer. First, that `WC_Order_Item_Product::get_product()`
 * really does behave the way `ReorderPlanner` assumes for a deleted product and
 * a deleted variation — the assumption the whole four-outcome summary rests on.
 * Second, that `WC_Cart::add_to_cart()` accepts what the planner produces,
 * including a variation's attributes, rather than only a double's version of it.
 *
 * @since 0.12.0
 *
 * @covers \PostPurchaseHub\Actions\Reorder
 * @covers \PostPurchaseHub\Actions\ReorderPlanner
 * @covers \PostPurchaseHub\Actions\WooCommerceCart
 */
final class ReorderTest extends \WP_UnitTestCase {

	/**
	 * Action under test, over WooCommerce's own cart.
	 *
	 * @var Reorder
	 */
	private Reorder $reorder;

	/**
	 * Builds the action and logs in a customer who owns nothing yet.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->reorder = new Reorder(
			new EligibilityResolver( new RequestRepository() ),
			new ReorderPlanner(),
			new WooCommerceCart( new Logger() )
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		if ( WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Empties the cart so one test's basket is never another's starting state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	/**
	 * A saved simple product.
	 *
	 * @param string   $name  Product name.
	 * @param string   $price Regular price.
	 * @param int|null $stock Stock quantity, null for unmanaged stock.
	 * @return \WC_Product_Simple
	 */
	private function product( string $name, string $price = '10.00', ?int $stock = null ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}

		$product->save();

		return $product;
	}

	/**
	 * A completed order owned by the current user.
	 *
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_id( get_current_user_id() );
		$order->set_billing_email( 'customer@example.com' );

		return $order;
	}

	/**
	 * Saves an order as completed.
	 *
	 * @param \WC_Order $order Order to complete.
	 * @return \WC_Order The order, re-read the way a request would read it.
	 */
	private function complete( \WC_Order $order ): \WC_Order {
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * The four outcomes of the acceptance criteria, against real products.
	 *
	 * @return void
	 */
	public function test_the_fixture_order_produces_four_correct_outcomes(): void {
		$in_stock     = $this->product( 'Espresso beans', '12.00', 10 );
		$out_of_stock = $this->product( 'Ceramic cup', '9.00', 0 );
		$doomed       = $this->product( 'Discontinued grinder', '99.00' );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'House blend' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '15.00' );
		$variation->save();

		$order = $this->order();
		$order->add_product( $in_stock, 2 );
		$order->add_product( $out_of_stock, 1 );
		$order->add_product( $doomed, 1 );
		$order->add_product( $variation, 1 );
		$order = $this->complete( $order );

		wp_delete_post( $doomed->get_id(), true );
		wp_delete_post( $variation->get_id(), true );

		$plan = $this->reorder->preview( $order );

		$outcomes = array();

		foreach ( $plan->lines as $line ) {
			$outcomes[ $line->name ] = $line->outcome;
		}

		$this->assertSame( ReorderLine::OUTCOME_ADDED, $outcomes['Espresso beans'] );
		$this->assertSame( ReorderLine::OUTCOME_OUT_OF_STOCK, $outcomes['Ceramic cup'] );
		$this->assertSame( ReorderLine::OUTCOME_UNAVAILABLE, $outcomes['Discontinued grinder'] );
		$this->assertSame( ReorderLine::OUTCOME_VARIATION_CHANGED, $outcomes['House blend'] );
		$this->assertCount( 1, $plan->addable() );
	}

	/**
	 * Previewing leaves WooCommerce's own cart exactly as it was.
	 *
	 * @return void
	 */
	public function test_previewing_never_touches_the_cart(): void {
		$other = $this->product( 'Filter papers', '4.00' );

		WC()->cart->add_to_cart( $other->get_id(), 1 );

		$order = $this->order();
		$order->add_product( $this->product( 'Espresso beans', '12.00', 10 ), 2 );
		$order = $this->complete( $order );

		$this->reorder->preview( $order );

		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	/**
	 * Merging keeps what was in the cart and adds the reordered line to it.
	 *
	 * @return void
	 */
	public function test_merging_keeps_the_existing_cart(): void {
		$other = $this->product( 'Filter papers', '4.00' );

		WC()->cart->add_to_cart( $other->get_id(), 1 );

		$order = $this->order();
		$order->add_product( $this->product( 'Espresso beans', '12.00', 10 ), 2 );
		$order = $this->complete( $order );

		$outcome = $this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$this->assertSame( 1, $outcome->added_count() );
		$this->assertCount( 2, WC()->cart->get_cart() );
	}

	/**
	 * Replacing empties the cart first, at the customer's own request.
	 *
	 * @return void
	 */
	public function test_replacing_empties_the_cart_first(): void {
		$other = $this->product( 'Filter papers', '4.00' );

		WC()->cart->add_to_cart( $other->get_id(), 1 );

		$order = $this->order();
		$order->add_product( $this->product( 'Espresso beans', '12.00', 10 ), 2 );
		$order = $this->complete( $order );

		$this->reorder->execute( $order, ReorderOptions::MODE_REPLACE );

		$cart = WC()->cart->get_cart();

		$this->assertCount( 1, $cart );
		$this->assertSame( 2, (int) reset( $cart )['quantity'] );
	}

	/**
	 * Stock is respected in the cart, not merely reported in the summary: the
	 * failure mode of core's own `order_again` is a cart holding more than the
	 * store can ship.
	 *
	 * @return void
	 */
	public function test_a_reduced_quantity_is_what_reaches_the_cart(): void {
		$order = $this->order();
		$order->add_product( $this->product( 'Espresso beans', '12.00', 2 ), 5 );
		$order = $this->complete( $order );

		$plan = $this->reorder->preview( $order );

		$this->assertSame( ReorderLine::OUTCOME_QUANTITY_REDUCED, $plan->lines[0]->outcome );

		$this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$cart = WC()->cart->get_cart();

		$this->assertCount( 1, $cart );
		$this->assertSame( 2, (int) reset( $cart )['quantity'] );
	}

	/**
	 * A variation still on sale is added with its attributes resolved, so the
	 * cart holds the variation the customer actually bought.
	 *
	 * @return void
	 */
	public function test_a_live_variation_reaches_the_cart(): void {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Grind' );
		$attribute->set_options( array( 'Coarse', 'Fine' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'House blend' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'grind' => 'Fine' ) );
		$variation->set_regular_price( '15.00' );
		$variation->save();

		$order = $this->order();
		$order->add_product( $variation, 1 );
		$order = $this->complete( $order );

		$plan = $this->reorder->preview( $order );

		$this->assertSame( ReorderLine::OUTCOME_ADDED, $plan->lines[0]->outcome );

		$this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$cart = WC()->cart->get_cart();

		$this->assertCount( 1, $cart );
		$this->assertSame( $variation->get_id(), (int) reset( $cart )['variation_id'] );
	}

	/**
	 * An order with nothing buyable left refuses, and leaves the cart alone —
	 * including in replace mode, where the customer would otherwise lose a
	 * basket in exchange for nothing.
	 *
	 * @return void
	 */
	public function test_nothing_available_leaves_the_cart_untouched(): void {
		$other = $this->product( 'Filter papers', '4.00' );

		WC()->cart->add_to_cart( $other->get_id(), 1 );

		$order = $this->order();
		$order->add_product( $this->product( 'Ceramic cup', '9.00', 0 ), 1 );
		$order = $this->complete( $order );

		$this->expectException( IneligibleActionException::class );

		try {
			$this->reorder->execute( $order, ReorderOptions::MODE_REPLACE );
		} finally {
			$this->assertCount( 1, WC()->cart->get_cart() );
		}
	}

	/**
	 * A price change since the order was placed is reported with its delta.
	 *
	 * @return void
	 */
	public function test_a_price_change_is_reported(): void {
		$product = $this->product( 'Espresso beans', '12.00', 10 );

		$order = $this->order();
		$order->add_product( $product, 1 );
		$order = $this->complete( $order );

		$product->set_regular_price( '14.50' );
		$product->save();

		$line = $this->reorder->preview( $order )->lines[0];

		$this->assertTrue( $line->price_changed() );
		$this->assertSame( 2.5, round( (float) $line->price_delta(), 2 ) );
	}

	/**
	 * A logged-out visitor cannot reorder, whatever they hold.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_cannot_reorder(): void {
		$order = $this->order();
		$order->add_product( $this->product( 'Espresso beans', '12.00', 10 ), 1 );
		$order = $this->complete( $order );

		wp_set_current_user( 0 );

		$result = $this->reorder->check( $order );

		$this->assertFalse( $result->eligible );
		$this->assertSame( Reorder::REASON_LOGIN_REQUIRED, $result->reason_code );
	}
}
