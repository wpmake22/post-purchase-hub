<?php
/**
 * Admin request resolution integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Admin\RequestListTable;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Requests\RequestService;

/**
 * Exercises the milestone's real database and real order dependencies: the
 * order transition and restock an approval performs, the reconciliation
 * hook closing a stale pending request, and the admin queue's constant
 * query count regardless of table size.
 *
 * @since 0.9.0
 *
 * @covers \PostPurchaseHub\Requests\RequestService
 * @covers \PostPurchaseHub\Actions\Cancel
 * @covers \PostPurchaseHub\Plugin
 * @covers \PostPurchaseHub\Admin\RequestListTable
 */
final class AdminRequestResolutionTest extends \WP_UnitTestCase {

	/**
	 * Creates the tables once, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		Schema::install();
	}

	/**
	 * A real, purchasable, stock-managed order with one line item.
	 *
	 * @param string $status Order status.
	 * @return \WC_Order
	 */
	private function order( string $status = 'processing' ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new \WC_Order();
		$order->add_product( $product, 2 );
		$order->set_status( $status );
		$order->save();

		// A stock-managed order only restocks what it actually reduced.
		wc_reduce_stock_levels( $order->get_id() );

		return $order;
	}

	/**
	 * A pending cancellation request for a given order.
	 *
	 * @param int $order_id Order id.
	 * @return Request
	 */
	private function pending_request( int $order_id ): Request {
		return ( new RequestRepository() )->find(
			( new RequestRepository() )->create(
				array(
					'order_id'            => $order_id,
					'customer_email_hash' => str_repeat( 'a', 64 ),
					'type'                => Request::TYPE_CANCELLATION,
					'source'              => Request::SOURCE_ACCOUNT,
				)
			)
		);
	}

	/**
	 * Approving transitions the order, restocks it, and writes a note — with
	 * no refund ever issued.
	 *
	 * @return void
	 */
	public function test_approve_transitions_and_restocks_the_order(): void {
		$order   = $this->order();
		$product = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_product();

		$request = $this->pending_request( $order->get_id() );
		$service = new RequestService( new RequestRepository() );

		$fired = null;
		add_action(
			'pph_request_approved',
			static function ( $approved_request, $approved_order ) use ( &$fired ): void {
				$fired = array( $approved_request, $approved_order );
			},
			10,
			2
		);

		$service->approve( $request, $order, 1 );
		( new Cancel( Plugin::instance()->eligibility_resolver(), $service ) )->approve( $order, 1 );

		$order = wc_get_order( $order->get_id() );

		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 5, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Stock reduced by 2 must be restored to its original 5.' );
		$this->assertNotNull( $fired, 'pph_request_approved must fire.' );
		$this->assertSame( Request::STATUS_APPROVED, ( new RequestRepository() )->find( $request->id )->status );
	}

	/**
	 * Approving restocks exactly once, not once per code path that could.
	 *
	 * The regression guard for the bug M16 found: WooCommerce restores stock
	 * on `woocommerce_order_status_cancelled` and `Actions\Cancel` used to
	 * restore it a second time afterwards, inflating inventory by a full order
	 * on every approval. Asserted on the product's own quantity rather than on
	 * which functions were called, because "restocked twice" and "restocked
	 * once" are indistinguishable from the call side.
	 *
	 * @return void
	 */
	public function test_approve_restocks_once_and_only_once(): void {
		$order   = $this->order();
		$product = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_product();
		$service = new RequestService( new RequestRepository() );

		$this->assertSame( 3, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Precondition: the order reduced stock from 5 to 3.' );

		$service->approve( $this->pending_request( $order->get_id() ), $order, 1 );
		( new Cancel( Plugin::instance()->eligibility_resolver(), $service ) )->approve( $order, 1 );

		$this->assertSame( 5, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Restored to 5. 7 means two restocks ran.' );
	}

	/**
	 * A merchant who turned restocking off gets no restock at all.
	 *
	 * The setting is expressed as a veto on WooCommerce's own restore rather
	 * than as a call of ours, so this asserts that the veto actually reaches
	 * core — the half of the fix a unit test with a fake WC_Order cannot prove.
	 *
	 * @return void
	 */
	public function test_approve_does_not_restock_when_the_merchant_turned_it_off(): void {
		update_option( 'pph_settings', array( Cancel::RESTOCK_SETTING => false ), false );

		$order   = $this->order();
		$product = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_product();
		$service = new RequestService( new RequestRepository() );

		$service->approve( $this->pending_request( $order->get_id() ), $order, 1 );
		( new Cancel( Plugin::instance()->eligibility_resolver(), $service ) )->approve( $order, 1 );

		delete_option( 'pph_settings' );

		$this->assertTrue( wc_get_order( $order->get_id() )->has_status( 'cancelled' ) );
		$this->assertSame( 3, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Stock stays where the order left it.' );
	}

	/**
	 * The veto is scoped to the one order and does not outlive the transition.
	 *
	 * A filter left registered would silently stop restocking every later
	 * cancellation in the same request — the kind of leak that only shows up
	 * on a store cancelling orders in bulk.
	 *
	 * @return void
	 */
	public function test_the_no_restock_veto_does_not_leak_to_the_next_order(): void {
		$service = new RequestService( new RequestRepository() );
		$cancel  = new Cancel( Plugin::instance()->eligibility_resolver(), $service );

		update_option( 'pph_settings', array( Cancel::RESTOCK_SETTING => false ), false );

		$suppressed = $this->order();
		$service->approve( $this->pending_request( $suppressed->get_id() ), $suppressed, 1 );
		$cancel->approve( $suppressed, 1 );

		delete_option( 'pph_settings' );

		$restocked = $this->order();
		$product   = $restocked->get_items()[ array_key_first( $restocked->get_items() ) ]->get_product();

		$service->approve( $this->pending_request( $restocked->get_id() ), $restocked, 1 );
		$cancel->approve( $restocked, 1 );

		$this->assertSame( 5, wc_get_product( $product->get_id() )->get_stock_quantity(), 'The second order restocks normally.' );
	}

	/**
	 * Declining never touches the order.
	 *
	 * @return void
	 */
	public function test_decline_never_touches_the_order(): void {
		$order   = $this->order();
		$request = $this->pending_request( $order->get_id() );
		$service = new RequestService( new RequestRepository() );

		$service->decline( $request, $order, 1, 'Outside the return window.' );

		$order = wc_get_order( $order->get_id() );

		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( Request::STATUS_DECLINED, ( new RequestRepository() )->find( $request->id )->status );
	}

	/**
	 * An order cancelled through any other route closes its pending request
	 * as completed, with a reconciliation note and no duplicate transition.
	 *
	 * @return void
	 */
	public function test_an_order_cancelled_by_another_route_reconciles_the_pending_request(): void {
		$order   = $this->order();
		$request = $this->pending_request( $order->get_id() );

		// Not this plugin's own approval path — a customer's own one-click
		// cancel, or a merchant changing status from the Orders screen, both
		// end up here: a plain status transition on the order.
		$order->update_status( 'cancelled' );

		$resolved = ( new RequestRepository() )->find( $request->id );

		$this->assertSame( Request::STATUS_COMPLETED, $resolved->status );
		$this->assertNotNull( $resolved->admin_note );

		$notes          = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$approval_notes = array_filter(
			$notes,
			static fn( $note ): bool => str_contains( $note->content, 'Cancellation request approved by' )
		);

		$this->assertSame(
			array(),
			$approval_notes,
			'The reconciliation path must never also run Cancel::approve() — no duplicate transition.'
		);
	}

	/**
	 * The admin queue's own query costs exactly the same, whether the table
	 * holds 5 rows or 500.
	 *
	 * @return void
	 */
	public function test_the_queue_query_count_is_constant_regardless_of_row_count(): void {
		$repository = new RequestRepository();

		for ( $i = 0; $i < 5; $i++ ) {
			$repository->create(
				array(
					'order_id'            => 1000 + $i,
					'customer_email_hash' => str_repeat( 'a', 64 ),
					'type'                => Request::TYPE_CANCELLATION,
					'source'              => Request::SOURCE_ACCOUNT,
				)
			);
		}

		$table_at_five = new RequestListTable( $repository );

		$before = get_num_queries();
		$table_at_five->prepare_items();
		$queries_at_five = get_num_queries() - $before;

		for ( $i = 5; $i < 500; $i++ ) {
			$repository->create(
				array(
					'order_id'            => 1000 + $i,
					'customer_email_hash' => str_repeat( 'a', 64 ),
					'type'                => Request::TYPE_CANCELLATION,
					'source'              => Request::SOURCE_ACCOUNT,
				)
			);
		}

		$table_at_five_hundred = new RequestListTable( $repository );

		$before = get_num_queries();
		$table_at_five_hundred->prepare_items();
		$queries_at_five_hundred = get_num_queries() - $before;

		$this->assertSame(
			$queries_at_five,
			$queries_at_five_hundred,
			'prepare_items() must cost the same number of queries whether the table holds 5 rows or 500.'
		);
	}
}
