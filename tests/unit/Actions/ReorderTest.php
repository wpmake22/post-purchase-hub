<?php
/**
 * Reorder action unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\EligibilityResult;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderOptions;
use PostPurchaseHub\Actions\ReorderLine;
use PostPurchaseHub\Actions\ReorderPlanner;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers docs/MILESTONE-PROMPTS.md M12's acceptance criteria one by one: the
 * four-outcome fixture order, every line unavailable, a changed price, the
 * merge-or-replace choice — and, above all, that nothing reaches the cart
 * before the customer confirms.
 *
 * @since 0.12.0
 *
 * @covers \PostPurchaseHub\Actions\Reorder
 * @covers \PostPurchaseHub\Actions\ReorderOptions
 * @covers \PostPurchaseHub\Actions\ReorderPlanner
 * @covers \PostPurchaseHub\Actions\ReorderLine
 * @covers \PostPurchaseHub\Actions\ReorderPlan
 * @covers \PostPurchaseHub\Actions\ReorderOutcome
 */
final class ReorderTest extends TestCase {

	/**
	 * Cart double.
	 *
	 * @var FakeCart
	 */
	private FakeCart $cart;

	/**
	 * Action under test.
	 *
	 * @var Reorder
	 */
	private Reorder $reorder;

	/**
	 * Builds the action over a real resolver and planner, and a fake cart.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_id = 7;

		$this->cart    = new FakeCart();
		$this->reorder = new Reorder(
			new EligibilityResolver( new FakeRequestHistory() ),
			new ReorderPlanner(),
			$this->cart
		);
	}

	/**
	 * A completed order owned by the current user.
	 *
	 * @param string $status Unprefixed order status.
	 * @return \WC_Order
	 */
	private function order( string $status = 'completed' ): \WC_Order {
		$order = new \WC_Order( 501, $status );
		$order->set_customer_id( 7 );
		$order->set_billing_email( 'customer@example.com' );

		FakeWordPress::$orders[501] = $order;

		return $order;
	}

	/**
	 * Registers a product the wc_get_product() shim will serve.
	 *
	 * @param int    $id       Product id.
	 * @param float  $price    Current price.
	 * @param int    $max      Units buyable now: -1 unlimited, 0 none.
	 * @param string $type     Product type.
	 * @return \WC_Product
	 */
	private function product( int $id, float $price = 10.0, int $max = -1, string $type = 'simple' ): \WC_Product {
		$product = new \WC_Product( $type, $id );
		$product->set_name( 'Product ' . $id );
		$product->set_price( $price );
		$product->set_max_purchase_quantity( $max );
		$product->set_permalink( 'https://example.test/product-' . $id );

		FakeWordPress::$products[ $id ] = $product;

		return $product;
	}

	/**
	 * Builds a line item.
	 *
	 * @param \WC_Product|null $product      Product the line resolves to, null when deleted.
	 * @param int              $product_id   Parent product id stored on the line.
	 * @param int              $quantity     Quantity bought.
	 * @param float            $unit_price   Price paid per unit.
	 * @param int              $variation_id Variation id.
	 * @return \WC_Order_Item_Product
	 */
	private function line( ?\WC_Product $product, int $product_id, int $quantity = 1, float $unit_price = 10.0, int $variation_id = 0 ): \WC_Order_Item_Product {
		$item = new \WC_Order_Item_Product( $product );
		$item->set_product_id( $product_id );
		$item->set_variation_id( $variation_id );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $unit_price * $quantity );
		$item->set_name( 'Line ' . $product_id );

		return $item;
	}

	// -----------------------------------------------------------------
	// Eligibility
	// -----------------------------------------------------------------

	/**
	 * A completed order owned by a logged-in customer is eligible.
	 *
	 * @return void
	 */
	public function test_a_completed_order_is_eligible(): void {
		$this->assertTrue( $this->reorder->check( $this->order() )->eligible );
	}

	/**
	 * A logged-out visitor is denied, whatever the order looks like.
	 *
	 * Enforced in the action, not in the template: a guest holding a valid
	 * signed link reaches the same code path over REST.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_denied(): void {
		FakeWordPress::$current_user_id = 0;

		$result = $this->reorder->check( $this->order() );

		$this->assertFalse( $result->eligible );
		$this->assertSame( Reorder::REASON_LOGIN_REQUIRED, $result->reason_code );
	}

	/**
	 * The order dimension answers about the order alone: a logged-out request
	 * does not make a completed order ineligible, it makes the request
	 * ineligible. Keeping the two separable is what stops one of them being
	 * quietly enforced in only one layer.
	 *
	 * @return void
	 */
	public function test_the_two_eligibility_dimensions_are_independent(): void {
		FakeWordPress::$current_user_id = 0;

		$order = $this->order();

		$this->assertTrue( $this->reorder->order_eligibility( $order )->eligible );
		$this->assertFalse( Reorder::visitor_eligibility()->eligible );
		$this->assertSame( Reorder::REASON_LOGIN_REQUIRED, $this->reorder->check( $order )->reason_code );

		FakeWordPress::$current_user_id = 7;

		$this->assertTrue( Reorder::visitor_eligibility()->eligible );
		$this->assertFalse( $this->reorder->order_eligibility( $this->order( 'processing' ) )->eligible );
	}

	/**
	 * A status core would not reorder is not reorderable here either.
	 *
	 * @return void
	 */
	public function test_a_processing_order_is_not_eligible_by_default(): void {
		$result = $this->reorder->check( $this->order( 'processing' ) );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_STATUS_NOT_ELIGIBLE, $result->reason_code );
	}

	/**
	 * Widening core's own filter widens this action with it.
	 *
	 * @return void
	 */
	public function test_core_status_filter_widens_eligibility(): void {
		add_filter(
			'woocommerce_valid_order_statuses_for_order_again',
			static fn (): array => array( 'completed', 'processing' )
		);

		$this->assertTrue( $this->reorder->check( $this->order( 'processing' ) )->eligible );
	}

	/**
	 * The plugin's own status filter is applied on top of core's.
	 *
	 * @return void
	 */
	public function test_plugin_status_filter_widens_eligibility(): void {
		add_filter( 'pph_reorder_allowed_statuses', static fn (): array => array( 'completed', 'on-hold' ) );

		$this->assertTrue( $this->reorder->check( $this->order( 'on-hold' ) )->eligible );
	}

	/**
	 * A subscription order is excluded, per docs/SPEC.md risk T5.
	 *
	 * @return void
	 */
	public function test_a_subscription_order_is_excluded(): void {
		$order = $this->order();
		$order->set_type( 'shop_subscription' );

		$result = $this->reorder->check( $order );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_ORDER_TYPE_EXCLUDED, $result->reason_code );
	}

	/**
	 * An order carrying a bookable product is excluded.
	 *
	 * @return void
	 */
	public function test_an_order_with_a_bookable_product_is_excluded(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 21, 10.0, -1, 'booking' ), 21 ) ) );

		$result = $this->reorder->check( $order );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_PRODUCT_TYPE_EXCLUDED, $result->reason_code );
	}

	// -----------------------------------------------------------------
	// resolve() — the rendered payload
	// -----------------------------------------------------------------

	/**
	 * An eligible order gets a link to its own summary screen.
	 *
	 * @return void
	 */
	public function test_resolve_returns_a_summary_link(): void {
		$payload = $this->reorder->resolve( $this->order(), 'detail' );

		$this->assertIsArray( $payload );
		$this->assertSame( Reorder::label(), $payload['name'] );
		$this->assertStringContainsString( Reorder::QUERY_ARG . '=501', $payload['url'] );
	}

	/**
	 * An ineligible order gets no payload at all.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_when_ineligible(): void {
		$this->assertNull( $this->reorder->resolve( $this->order( 'processing' ), 'detail' ) );
	}

	/**
	 * The action registers itself in both contexts.
	 *
	 * @return void
	 */
	public function test_register_adds_the_action_to_both_contexts(): void {
		$registry = new ActionRegistry();
		$this->reorder->register( $registry );

		$action = $registry->get( Reorder::ID );

		$this->assertNotNull( $action );
		$this->assertTrue( $action->applies_to( 'list' ) );
		$this->assertTrue( $action->applies_to( 'detail' ) );
	}

	// -----------------------------------------------------------------
	// preview() — the reconciliation summary, per failure mode
	// -----------------------------------------------------------------

	/**
	 * The fixture order from the acceptance criteria: one in-stock item, one
	 * out-of-stock item, one deleted product and one deleted variation produce
	 * four correct, explicit lines — and touch no cart.
	 *
	 * @return void
	 */
	public function test_the_fixture_order_produces_four_explicit_outcomes(): void {
		$order = $this->order();

		$in_stock     = $this->product( 31, 10.0 );
		$out_of_stock = $this->product( 32, 10.0, 0 );
		$parent       = $this->product( 34, 10.0 );

		$order->set_items(
			array(
				$this->line( $in_stock, 31, 2 ),
				$this->line( $out_of_stock, 32 ),
				$this->line( null, 33 ),
				$this->line( null, 34, 1, 10.0, 3401 ),
			)
		);

		$plan = $this->reorder->preview( $order );

		$this->assertCount( 4, $plan->lines );
		$this->assertSame( ReorderLine::OUTCOME_ADDED, $plan->lines[0]->outcome );
		$this->assertSame( 2, $plan->lines[0]->quantity );
		$this->assertSame( ReorderLine::OUTCOME_OUT_OF_STOCK, $plan->lines[1]->outcome );
		$this->assertSame( ReorderLine::OUTCOME_UNAVAILABLE, $plan->lines[2]->outcome );
		$this->assertSame( ReorderLine::OUTCOME_VARIATION_CHANGED, $plan->lines[3]->outcome );
		$this->assertSame( $parent->get_permalink(), $plan->lines[3]->url );
		$this->assertTrue( $this->cart->untouched(), 'Previewing must never touch the cart.' );
	}

	/**
	 * A product that still exists but can no longer be bought is unavailable,
	 * not out of stock — the two are different sentences to a customer.
	 *
	 * @return void
	 */
	public function test_an_unpurchasable_product_is_unavailable(): void {
		$order   = $this->order();
		$product = $this->product( 41 );
		$product->set_purchasable( false );

		$order->set_items( array( $this->line( $product, 41 ) ) );

		$this->assertSame( ReorderLine::OUTCOME_UNAVAILABLE, $this->reorder->preview( $order )->lines[0]->outcome );
	}

	/**
	 * Partial stock reduces the quantity explicitly rather than adding the
	 * full amount or refusing the line outright.
	 *
	 * @return void
	 */
	public function test_partial_stock_reduces_the_quantity_explicitly(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 51, 10.0, 2 ), 51, 5 ) ) );

		$line = $this->reorder->preview( $order )->lines[0];

		$this->assertSame( ReorderLine::OUTCOME_QUANTITY_REDUCED, $line->outcome );
		$this->assertSame( 5, $line->requested_quantity );
		$this->assertSame( 2, $line->quantity );
		$this->assertTrue( $line->is_addable() );
	}

	/**
	 * A variation that resolves to a different parent than the one bought is
	 * a changed variation, not a silent substitution.
	 *
	 * @return void
	 */
	public function test_a_reparented_variation_is_reported_as_changed(): void {
		$order     = $this->order();
		$variation = $this->product( 6101, 10.0 );
		$variation->set_parent_id( 999 );
		$this->product( 61 );

		$order->set_items( array( $this->line( $variation, 61, 1, 10.0, 6101 ) ) );

		$this->assertSame( ReorderLine::OUTCOME_VARIATION_CHANGED, $this->reorder->preview( $order )->lines[0]->outcome );
	}

	/**
	 * A deleted variation whose parent is gone too is simply unavailable.
	 *
	 * @return void
	 */
	public function test_a_deleted_variation_with_no_parent_is_unavailable(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( null, 71, 1, 10.0, 7101 ) ) );

		$this->assertSame( ReorderLine::OUTCOME_UNAVAILABLE, $this->reorder->preview( $order )->lines[0]->outcome );
	}

	/**
	 * A variable parent with no variation recorded on the line cannot be put
	 * in a cart, so it is reported as a changed variation rather than as an
	 * addable line that the cart would then refuse.
	 *
	 * @return void
	 */
	public function test_a_variable_line_with_no_variation_is_reported_as_changed(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 75, 10.0, -1, 'variable' ), 75 ) ) );

		$plan = $this->reorder->preview( $order );

		$this->assertSame( ReorderLine::OUTCOME_VARIATION_CHANGED, $plan->lines[0]->outcome );
		$this->assertFalse( $plan->lines[0]->is_addable() );
		$this->assertSame( 'https://example.test/product-75', $plan->lines[0]->url );
	}

	/**
	 * A price rise is reported with its delta, on a line that still adds.
	 *
	 * @return void
	 */
	public function test_a_price_rise_is_reported_with_its_delta(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 81, 12.5 ), 81, 2, 10.0 ) ) );

		$line = $this->reorder->preview( $order )->lines[0];

		$this->assertSame( ReorderLine::OUTCOME_ADDED, $line->outcome );
		$this->assertTrue( $line->price_changed() );
		$this->assertSame( 2.5, $line->price_delta() );
	}

	/**
	 * An unchanged price reports no change, and a rounding-level difference
	 * counts as unchanged rather than as a delta that formats to zero.
	 *
	 * @return void
	 */
	public function test_an_unchanged_price_reports_no_change(): void {
		$order = $this->order();
		$order->set_items(
			array(
				$this->line( $this->product( 91, 10.0 ), 91, 1, 10.0 ),
				$this->line( $this->product( 92, 10.001 ), 92, 1, 10.0 ),
			)
		);

		$plan = $this->reorder->preview( $order );

		$this->assertFalse( $plan->lines[0]->price_changed() );
		$this->assertFalse( $plan->lines[1]->price_changed() );
	}

	/**
	 * An order in a currency the store no longer sells in reports no delta at
	 * all, rather than subtracting two different currencies.
	 *
	 * @return void
	 */
	public function test_a_foreign_currency_order_reports_no_delta(): void {
		$order = $this->order();
		$order->set_currency( 'JPY' );
		$order->set_items( array( $this->line( $this->product( 101, 999.0 ), 101, 1, 10.0 ) ) );

		$line = $this->reorder->preview( $order )->lines[0];

		$this->assertNull( $line->price_delta() );
		$this->assertFalse( $line->price_changed() );
		$this->assertTrue( $line->is_addable() );
	}

	/**
	 * The item cap bounds the work and says so, rather than dropping lines.
	 *
	 * @return void
	 */
	public function test_the_item_cap_marks_the_rest_unchecked(): void {
		add_filter( 'pph_reorder_item_cap', static fn (): int => 2 );

		$order = $this->order();
		$order->set_items(
			array(
				$this->line( $this->product( 111 ), 111 ),
				$this->line( $this->product( 112 ), 112 ),
				$this->line( $this->product( 113 ), 113 ),
			)
		);

		$plan = $this->reorder->preview( $order );

		$this->assertSame( ReorderLine::OUTCOME_ADDED, $plan->lines[0]->outcome );
		$this->assertSame( ReorderLine::OUTCOME_ADDED, $plan->lines[1]->outcome );
		$this->assertSame( ReorderLine::OUTCOME_NOT_CHECKED, $plan->lines[2]->outcome );
		$this->assertTrue( $plan->was_capped() );
		$this->assertCount( 2, $plan->addable() );
	}

	/**
	 * Variation attributes travel with the line, so the cart can resolve an
	 * `any` variation the way core's own handler does.
	 *
	 * @return void
	 */
	public function test_variation_attributes_are_carried_on_the_line(): void {
		FakeWordPress::$custom_attributes = array( 'Engraving' );

		$order     = $this->order();
		$variation = $this->product( 12101 );
		$variation->set_parent_id( 121 );
		$this->product( 121 );

		$item = $this->line( $variation, 121, 1, 10.0, 12101 );
		$item->set_meta_pairs(
			array(
				'pa_colour' => 'Deep Blue',
				'Engraving' => 'For Ada',
				'_internal' => 'ignored',
			)
		);

		$order->set_items( array( $item ) );

		$attributes = $this->reorder->preview( $order )->lines[0]->attributes;

		$this->assertSame( 'deep-blue', $attributes['attribute_pa_colour'] );
		$this->assertSame( 'For Ada', $attributes['attribute_engraving'] );
		$this->assertArrayNotHasKey( 'attribute_-internal', $attributes );
	}

	// -----------------------------------------------------------------
	// execute() — the only path that writes a cart
	// -----------------------------------------------------------------

	/**
	 * Confirming adds the addable lines and leaves the existing cart alone in
	 * merge mode.
	 *
	 * @return void
	 */
	public function test_merge_adds_without_clearing(): void {
		$this->cart->existing = 3;

		$order = $this->order();
		$order->set_items(
			array(
				$this->line( $this->product( 131 ), 131, 2 ),
				$this->line( $this->product( 132, 10.0, 0 ), 132 ),
			)
		);

		$outcome = $this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$this->assertSame( ReorderOptions::MODE_MERGE, $outcome->mode );
		$this->assertSame( 1, $outcome->added_count() );
		$this->assertSame( 2, $outcome->quantity_added() );
		$this->assertSame( 0, $this->cart->clears );
	}

	/**
	 * Replace empties the cart first, and only once eligibility has passed.
	 *
	 * @return void
	 */
	public function test_replace_clears_the_cart_first(): void {
		$this->cart->existing = 3;

		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 141 ), 141 ) ) );

		$this->reorder->execute( $order, ReorderOptions::MODE_REPLACE );

		$this->assertSame( 1, $this->cart->clears );
		$this->assertCount( 1, $this->cart->added );
	}

	/**
	 * An unrecognised mode falls back to merging rather than emptying a cart
	 * nobody asked to empty.
	 *
	 * @return void
	 */
	public function test_an_unknown_mode_falls_back_to_merge(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 151 ), 151 ) ) );

		$outcome = $this->reorder->execute( $order, 'obliterate' );

		$this->assertSame( ReorderOptions::MODE_MERGE, $outcome->mode );
		$this->assertSame( 0, $this->cart->clears );
	}

	/**
	 * When every line is unavailable the cart is left exactly as it was, even
	 * in replace mode.
	 *
	 * @return void
	 */
	public function test_nothing_available_leaves_the_cart_untouched(): void {
		$this->cart->existing = 2;

		$order = $this->order();
		$order->set_items(
			array(
				$this->line( $this->product( 161, 10.0, 0 ), 161 ),
				$this->line( null, 162 ),
			)
		);

		try {
			$this->reorder->execute( $order, ReorderOptions::MODE_REPLACE );
			$this->fail( 'Expected an IneligibleActionException.' );
		} catch ( IneligibleActionException $e ) {
			$this->assertSame( Reorder::REASON_NOTHING_AVAILABLE, $e->result->reason_code );
			$this->assertNotSame( '', $e->result->message );
		}

		$this->assertTrue( $this->cart->untouched() );
		$this->assertSame( 2, $this->cart->item_count() );
	}

	/**
	 * Eligibility is re-checked at execution time, not trusted from the fact
	 * that a button was once rendered.
	 *
	 * @return void
	 */
	public function test_execute_rechecks_eligibility(): void {
		$order = $this->order( 'processing' );
		$order->set_items( array( $this->line( $this->product( 171 ), 171 ) ) );

		try {
			$this->reorder->execute( $order, ReorderOptions::MODE_MERGE );
			$this->fail( 'Expected an IneligibleActionException.' );
		} catch ( IneligibleActionException $e ) {
			$this->assertSame( EligibilityResult::REASON_STATUS_NOT_ELIGIBLE, $e->result->reason_code );
		}

		$this->assertTrue( $this->cart->untouched() );
	}

	/**
	 * A line the cart refuses between summary and confirmation is reported,
	 * not silently dropped.
	 *
	 * @return void
	 */
	public function test_a_line_the_cart_refuses_is_reported(): void {
		$order = $this->order();
		$order->set_items(
			array(
				$this->line( $this->product( 181 ), 181 ),
				$this->line( $this->product( 182 ), 182 ),
			)
		);

		$this->cart->refuse = array( 182 );

		$outcome = $this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$this->assertSame( 1, $outcome->added_count() );
		$this->assertCount( 1, $outcome->rejected );
		$this->assertSame( 182, $outcome->rejected[0]->product_id );
	}

	/**
	 * The completion hook fires with the order and the outcome.
	 *
	 * @return void
	 */
	public function test_the_completion_hook_fires(): void {
		$order = $this->order();
		$order->set_items( array( $this->line( $this->product( 191 ), 191 ) ) );

		$seen = 0;

		add_action(
			'pph_reorder_completed',
			static function () use ( &$seen ): void {
				++$seen;
			}
		);

		$this->reorder->execute( $order, ReorderOptions::MODE_MERGE );

		$this->assertSame( 1, $seen );
	}

	// -----------------------------------------------------------------
	// Configuration
	// -----------------------------------------------------------------

	/**
	 * The default mode is merge, and it is filterable.
	 *
	 * @return void
	 */
	public function test_the_default_mode_is_merge_and_filterable(): void {
		$this->assertSame( ReorderOptions::MODE_MERGE, ReorderOptions::default_mode() );

		add_filter( 'pph_reorder_default_mode', static fn (): string => ReorderOptions::MODE_REPLACE );

		$this->assertSame( ReorderOptions::MODE_REPLACE, ReorderOptions::default_mode() );
	}

	/**
	 * A filter returning nonsense cannot produce a mode the action does not
	 * understand.
	 *
	 * @return void
	 */
	public function test_a_nonsense_default_mode_falls_back_to_merge(): void {
		add_filter( 'pph_reorder_default_mode', static fn (): string => 'nonsense' );

		$this->assertSame( ReorderOptions::MODE_MERGE, ReorderOptions::default_mode() );
	}

	/**
	 * The item cap has a floor: a filter cannot switch validation off.
	 *
	 * @return void
	 */
	public function test_the_item_cap_cannot_be_zero(): void {
		add_filter( 'pph_reorder_item_cap', static fn (): int => 0 );

		$this->assertSame( 1, ReorderOptions::item_cap() );
	}

	/**
	 * Prefixed statuses from a filter are normalised, and an empty list falls
	 * back to core's own default.
	 *
	 * @return void
	 */
	public function test_status_filters_are_normalised(): void {
		add_filter( 'pph_reorder_allowed_statuses', static fn (): array => array( 'wc-completed' ) );

		$this->assertSame( array( 'completed' ), ReorderOptions::allowed_statuses() );
	}

	/**
	 * An empty status list falls back rather than disabling the action in a
	 * way no filter asked for.
	 *
	 * @return void
	 */
	public function test_an_empty_status_list_falls_back(): void {
		add_filter( 'pph_reorder_allowed_statuses', static fn (): array => array() );

		$this->assertSame( array( 'completed' ), ReorderOptions::allowed_statuses() );
	}

	/**
	 * The action label never promises an order was placed.
	 *
	 * @return void
	 */
	public function test_the_label_does_not_claim_a_purchase(): void {
		$this->assertStringNotContainsStringIgnoringCase( 'order placed', Reorder::label() );
		$this->assertNotSame( '', Reorder::label() );
	}
}
