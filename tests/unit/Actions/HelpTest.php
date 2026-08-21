<?php
/**
 * Help action unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContext;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Actions\HelpTopics;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Emails\HelpRequest;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M13's second acceptance criterion on the way in: a submission arrives with
 * the full order context, and nothing a customer typed arrives unsanitised or
 * uncapped.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Actions\Help
 * @covers \PostPurchaseHub\Actions\HelpContext
 * @covers \PostPurchaseHub\Actions\HelpContextBuilder
 * @covers \PostPurchaseHub\Actions\HelpTopics
 */
final class HelpTest extends TestCase {

	/**
	 * Action under test.
	 *
	 * @var Help
	 */
	private Help $help;

	/**
	 * Submissions recorded from `pph_help_submitted`.
	 *
	 * @var array<int, HelpContext>
	 */
	private array $submitted = array();

	/**
	 * Builds the action over the real eligibility, timeline and context layers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->submitted = array();

		$stages   = new StageMap( new StatusDetector( new Cache() ) );
		$timeline = new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) );

		$this->help = new Help(
			new EligibilityResolver( new FakeRequestHistory() ),
			new HelpContextBuilder( $timeline )
		);

		$recorder = function ( HelpContext $context ): void {
			$this->submitted[] = $context;
		};

		FakeWordPress::$actions['pph_help_submitted'][] = array(
			'callback' => $recorder,
			'priority' => 10,
		);
	}

	/**
	 * An order with two line items.
	 *
	 * @param string $status Unprefixed order status.
	 * @return \WC_Order
	 */
	private function order( string $status = 'processing' ): \WC_Order {
		$order = new \WC_Order( 8001, $status );
		$order->set_billing_email( 'customer@example.test' );
		$order->set_items( array( $this->item( 'Blue shirt', 2 ), $this->item( 'Wool socks', 1 ) ) );

		FakeWordPress::$orders[8001] = $order;

		return $order;
	}

	/**
	 * A line item.
	 *
	 * @param string $name     Product name.
	 * @param int    $quantity Quantity ordered.
	 * @return \WC_Order_Item_Product
	 */
	private function item( string $name, int $quantity ): \WC_Order_Item_Product {
		$item = new \WC_Order_Item_Product();
		$item->set_name( $name );
		$item->set_quantity( $quantity );

		return $item;
	}

	/**
	 * The context carries everything the milestone names: order number, status,
	 * item summary and the current timeline state.
	 *
	 * @return void
	 */
	public function test_the_context_carries_the_order(): void {
		$context = $this->help->context_for( $this->order() );

		$this->assertSame( 8001, $context->order_id );
		$this->assertSame( '8001', $context->order_number );
		$this->assertSame( 'processing', $context->status );
		$this->assertNotSame( '', $context->status_label );
		$this->assertNotSame( '', $context->timeline_state, 'The stage the order is sitting in is part of the hand-off.' );
		$this->assertSame( array( '2 × Blue shirt', '1 × Wool socks' ), $context->items );
		$this->assertSame( 0, $context->items_omitted );
		$this->assertSame( 'customer@example.test', $context->customer_email );
	}

	/**
	 * The item summary is capped, and what is left off is counted rather than
	 * silently dropped.
	 *
	 * @return void
	 */
	public function test_the_item_summary_is_capped(): void {
		$order = $this->order();
		$items = array();

		for ( $i = 0; $i < HelpContextBuilder::ITEM_CAP + 5; $i++ ) {
			$items[] = $this->item( 'Item ' . $i, 1 );
		}

		$order->set_items( $items );

		$context = $this->help->context_for( $order );

		$this->assertCount( HelpContextBuilder::ITEM_CAP, $context->items );
		$this->assertSame( 5, $context->items_omitted );
	}

	/**
	 * A submission fires the hand-off action with the order context attached.
	 *
	 * @return void
	 */
	public function test_a_submission_hands_off_with_context(): void {
		$order   = $this->order();
		$context = $this->help->submit( $order, 'where_is_my_order', 'It has not arrived yet.', Help::SOURCE_ACCOUNT );

		$this->assertCount( 1, $this->submitted );
		$this->assertSame( $context, $this->submitted[0] );
		$this->assertSame( 'It has not arrived yet.', $context->message );
		$this->assertSame( 'where_is_my_order', $context->topic );
		$this->assertSame( HelpTopics::label_for( 'where_is_my_order' ), $context->topic_label );
		$this->assertSame( Help::SOURCE_ACCOUNT, $context->source );
		$this->assertSame( '8001', $context->order_number );
		$this->assertNotSame( '', $context->admin_url, 'The merchant gets a deep link to the order.' );
	}

	/**
	 * Markup in the message is stripped on the way through, so no consumer of
	 * the action receives a payload with tags in it.
	 *
	 * @return void
	 */
	public function test_the_message_is_stripped_of_markup(): void {
		$context = $this->help->submit(
			$this->order(),
			'other',
			'<script>alert(1)</script> Please call me',
			Help::SOURCE_ACCOUNT
		);

		$this->assertStringNotContainsString( '<script>', $context->message );
		$this->assertStringContainsString( 'Please call me', $context->message );
	}

	/**
	 * The message is capped at the action's own limit.
	 *
	 * @return void
	 */
	public function test_the_message_is_length_capped(): void {
		$context = $this->help->submit(
			$this->order(),
			'other',
			str_repeat( 'a', Help::MESSAGE_MAX_LENGTH + 500 ),
			Help::SOURCE_ACCOUNT
		);

		$this->assertSame( Help::MESSAGE_MAX_LENGTH, mb_strlen( $context->message ) );
	}

	/**
	 * A message that is empty once stripped is rejected rather than handed off
	 * as an empty question.
	 *
	 * @return void
	 * @throws IneligibleActionException Re-thrown after its reason is asserted.
	 */
	public function test_an_empty_message_is_rejected(): void {
		$this->expectException( IneligibleActionException::class );

		try {
			$this->help->submit( $this->order(), 'other', '<b></b>   ', Help::SOURCE_ACCOUNT );
		} catch ( IneligibleActionException $e ) {
			$this->assertSame( Help::REASON_EMPTY_MESSAGE, $e->result->reason_code );
			$this->assertSame( array(), $this->submitted );

			throw $e;
		}
	}

	/**
	 * A topic outside the server-side whitelist is rejected, whatever the form
	 * sent.
	 *
	 * @return void
	 * @throws IneligibleActionException Re-thrown after its reason is asserted.
	 */
	public function test_an_unknown_topic_is_rejected(): void {
		$this->expectException( IneligibleActionException::class );

		try {
			$this->help->submit( $this->order(), 'refund_me_now', 'Hello', Help::SOURCE_ACCOUNT );
		} catch ( IneligibleActionException $e ) {
			$this->assertSame( Help::REASON_UNKNOWN_TOPIC, $e->result->reason_code );
			$this->assertSame( array(), $this->submitted );

			throw $e;
		}
	}

	/**
	 * A guest's submission is recorded as a guest's, not silently promoted.
	 *
	 * @return void
	 */
	public function test_a_guest_submission_is_marked_as_one(): void {
		$context = $this->help->submit( $this->order(), 'other', 'Hello', Help::SOURCE_GUEST );

		$this->assertSame( Help::SOURCE_GUEST, $context->source );
	}

	/**
	 * With the email off and nothing else listening, the action is not offered
	 * and a submission is refused — a form with no destination is worse than no
	 * form.
	 *
	 * @return void
	 */
	public function test_no_destination_means_no_action(): void {
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'enabled' => 'no' );

		$order = $this->order();

		$this->assertFalse( $this->help->check( $order )->eligible );
		$this->assertSame( Help::REASON_NO_DESTINATION, $this->help->check( $order )->reason_code );
		$this->assertNull( $this->help->resolve( $order, 'detail' ) );

		$this->expectException( IneligibleActionException::class );

		$this->help->submit( $order, 'other', 'Hello', Help::SOURCE_ACCOUNT );
	}

	/**
	 * A helpdesk integration consuming the action can keep the form alive with
	 * the email switched off.
	 *
	 * @return void
	 */
	public function test_a_helpdesk_can_be_the_destination(): void {
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'enabled' => 'no' );

		FakeWordPress::$filters['pph_help_destination_exists'][] = static function (): bool {
			return true;
		};

		$this->assertTrue( $this->help->check( $this->order() )->eligible );
	}

	/**
	 * The link goes to the form on the order's own page from the list, and to
	 * the same page's fragment on the detail view.
	 *
	 * @return void
	 */
	public function test_the_link_points_at_the_form(): void {
		$order = $this->order();

		$list   = $this->help->resolve( $order, 'list' );
		$detail = $this->help->resolve( $order, 'detail' );

		$this->assertNotNull( $list );
		$this->assertNotNull( $detail );
		$this->assertSame( $order->get_view_order_url() . '#pph-help-8001', $list['url'] );
		$this->assertSame( '#pph-help-8001', $detail['url'] );
	}

	/**
	 * Any order a customer can see is one they can ask about — including a
	 * cancelled one, where a question is most likely.
	 *
	 * @return void
	 */
	public function test_every_status_may_be_asked_about(): void {
		foreach ( array( 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed' ) as $status ) {
			$this->assertTrue(
				$this->help->check( $this->order( $status ) )->eligible,
				'A ' . $status . ' order can still be asked about.'
			);
		}
	}

	/**
	 * A filter can restrict the topics, and the whitelist follows it.
	 *
	 * @return void
	 */
	public function test_topics_are_filterable(): void {
		FakeWordPress::$filters['pph_help_topics'][] = static function (): array {
			return array( 'only_this' );
		};

		$this->assertSame( array( 'only_this' ), HelpTopics::codes() );
		$this->assertSame( 'Only this', HelpTopics::label_for( 'only_this' ) );
		$this->assertNull( HelpTopics::normalise( 'other' ) );
	}

	/**
	 * The action registers itself for both contexts.
	 *
	 * @return void
	 */
	public function test_it_registers_for_both_contexts(): void {
		$registry = new ActionRegistry();

		$this->help->register( $registry );

		$registered = $registry->get( Help::ID );

		$this->assertNotNull( $registered );
		$this->assertTrue( $registered->applies_to( 'list' ) );
		$this->assertTrue( $registered->applies_to( 'detail' ) );
	}
}
