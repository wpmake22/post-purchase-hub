<?php
/**
 * Cancel action unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the cancellation action's own rule, its render payload, and —
 * critically — that execute() re-checks eligibility and never reaches
 * persistence when it fails.
 *
 * @since 0.8.0
 *
 * @covers \PostPurchaseHub\Actions\Cancel
 */
final class CancelTest extends TestCase {

	/**
	 * Request-lifecycle double.
	 *
	 * @var FakeRequestLifecycle
	 */
	private FakeRequestLifecycle $lifecycle;

	/**
	 * Cancel under test.
	 *
	 * @var Cancel
	 */
	private Cancel $cancel;

	/**
	 * Builds Cancel over a real EligibilityResolver and a fake lifecycle.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->lifecycle = new FakeRequestLifecycle();
		$this->cancel    = new Cancel( new EligibilityResolver( new FakeRequestHistory() ), $this->lifecycle );
	}

	/**
	 * The default reason codes are the documented set.
	 *
	 * @return void
	 */
	public function test_default_reason_codes(): void {
		$this->assertSame( Cancel::DEFAULT_REASON_CODES, Cancel::reason_codes() );
	}

	/**
	 * The reason codes are filterable.
	 *
	 * @return void
	 */
	public function test_reason_codes_are_filterable(): void {
		add_filter( 'wpmphub_cancel_reason_codes', static fn (): array => array( 'custom_code' ) );

		$this->assertSame( array( 'custom_code' ), Cancel::reason_codes() );
	}

	/**
	 * A filter returning nothing usable falls back to the default set.
	 *
	 * @return void
	 */
	public function test_an_empty_filtered_reason_list_falls_back_to_defaults(): void {
		add_filter( 'wpmphub_cancel_reason_codes', static fn (): array => array() );

		$this->assertSame( Cancel::DEFAULT_REASON_CODES, Cancel::reason_codes() );
	}

	/**
	 * Every default reason code has a non-empty label.
	 *
	 * @return void
	 */
	public function test_every_default_reason_code_has_a_label(): void {
		$labels = Cancel::reason_code_labels();

		foreach ( Cancel::DEFAULT_REASON_CODES as $code ) {
			$this->assertArrayHasKey( $code, $labels );
			$this->assertNotSame( '', $labels[ $code ] );
		}
	}

	/**
	 * A custom reason code added only via the codes filter still gets a label,
	 * humanised from the slug.
	 *
	 * @return void
	 */
	public function test_a_custom_code_without_a_label_is_humanised(): void {
		add_filter( 'wpmphub_cancel_reason_codes', static fn (): array => array( 'store_credit_instead' ) );

		$this->assertSame(
			array( 'store_credit_instead' => 'Store credit instead' ),
			Cancel::reason_code_labels()
		);
	}

	/**
	 * An order in an eligible status resolves to a render payload.
	 *
	 * @return void
	 */
	public function test_resolve_returns_a_payload_for_an_eligible_order(): void {
		$order = new \WC_Order( 1, 'processing' );

		$payload = $this->cancel->resolve( $order, 'list' );

		$this->assertNotNull( $payload );
		$this->assertSame( Cancel::label(), $payload['name'] );
		$this->assertSame( '#wpmphub-cancel-1', $payload['url'] );
	}

	/**
	 * An order in an ineligible status resolves to null — no button.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_for_an_ineligible_order(): void {
		$order = new \WC_Order( 1, 'completed' );

		$this->assertNull( $this->cancel->resolve( $order, 'list' ) );
	}

	/**
	 * Check() reflects the same eligibility resolve() renders from.
	 *
	 * @return void
	 */
	public function test_check_reflects_the_configured_allowed_statuses(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::STATUSES_SETTING => array( 'on-hold' ) );

		$this->assertTrue( $this->cancel->check( new \WC_Order( 1, 'on-hold' ) )->eligible );
		$this->assertFalse( $this->cancel->check( new \WC_Order( 1, 'processing' ) )->eligible );
	}

	/**
	 * The hard exclusions apply: a subscription order is never eligible.
	 *
	 * @return void
	 */
	public function test_a_subscription_order_is_never_eligible(): void {
		$order = new \WC_Order( 1, 'processing' );
		$order->set_type( 'shop_subscription' );

		$this->assertFalse( $this->cancel->check( $order )->eligible );
	}

	/**
	 * Register() adds this action to the registry under the fixed id, for
	 * both contexts, sharing WooCommerce core's own `cancel` key.
	 *
	 * @return void
	 */
	public function test_register_wires_the_action_under_the_fixed_id(): void {
		$registry = new ActionRegistry();

		$this->cancel->register( $registry );

		$action = $registry->get( Cancel::ID );

		$this->assertNotNull( $action );
		$this->assertSame( 'cancel', $action->id );
		$this->assertSame( array( 'list', 'detail' ), $action->contexts );
	}

	/**
	 * Execute() on an eligible order creates the request with sanitised,
	 * correctly hashed data, and returns it.
	 *
	 * @return void
	 */
	public function test_execute_creates_a_request_for_an_eligible_order(): void {
		$order = new \WC_Order( 42, 'processing' );
		$order->set_billing_email( 'Jane.Doe@Example.com' );

		$created = $this->cancel->execute( $order, 'changed_mind', 'Please cancel', Request::SOURCE_ACCOUNT, 7 );

		$this->assertCount( 1, $this->lifecycle->created );
		$data = $this->lifecycle->created[0];

		$this->assertSame( 42, $data['order_id'] );
		$this->assertSame( 7, $data['customer_id'] );
		$this->assertSame( Request::TYPE_CANCELLATION, $data['type'] );
		$this->assertSame( 'changed_mind', $data['reason_code'] );
		$this->assertSame( 'Please cancel', $data['customer_note'] );
		$this->assertSame( Request::SOURCE_ACCOUNT, $data['source'] );
		$this->assertSame(
			hash( 'sha256', 'janedoe@example.com' ),
			$data['customer_email_hash'],
			'Email hash must use the normalised address (lower-cased, dots folded out of the local part).'
		);
		$this->assertSame( $data['order_id'], $created->order_id );
	}

	/**
	 * An unrecognised reason code is normalised to null rather than stored as-is.
	 *
	 * @return void
	 */
	public function test_execute_rejects_an_unrecognised_reason_code(): void {
		$order = new \WC_Order( 1, 'processing' );

		$this->cancel->execute( $order, '<script>', '', Request::SOURCE_ACCOUNT, 1 );

		$this->assertNull( $this->lifecycle->created[0]['reason_code'] );
	}

	/**
	 * An empty note is stored as null, not as an empty string.
	 *
	 * @return void
	 */
	public function test_execute_stores_an_empty_note_as_null(): void {
		$order = new \WC_Order( 1, 'processing' );

		$this->cancel->execute( $order, 'other', '', Request::SOURCE_ACCOUNT, 1 );

		$this->assertNull( $this->lifecycle->created[0]['customer_note'] );
	}

	/**
	 * The "action executor": an ineligible order throws instead of creating
	 * anything — called directly, exactly as a REST controller would, with no
	 * UI in the path at all.
	 *
	 * @return void
	 */
	public function test_execute_throws_and_creates_nothing_for_an_ineligible_order(): void {
		$order = new \WC_Order( 1, 'completed' );

		try {
			$this->cancel->execute( $order, 'other', '', Request::SOURCE_ACCOUNT, 1 );
			$this->fail( 'Expected an IneligibleActionException.' );
		} catch ( IneligibleActionException $e ) {
			$this->assertFalse( $e->result->eligible );
		}

		$this->assertSame( array(), $this->lifecycle->created, 'An ineligible order must never reach create().' );
	}

	/**
	 * A client-forged "eligible" belief is irrelevant: execute() re-runs the
	 * same check(), not whatever resolve() returned earlier.
	 *
	 * @return void
	 */
	public function test_execute_re_checks_eligibility_independently_of_resolve(): void {
		$order = new \WC_Order( 1, 'processing' );

		// A button would have been shown for this order a moment ago...
		$this->assertNotNull( $this->cancel->resolve( $order, 'list' ) );

		// ...but the order moved on by the time the request actually arrives.
		$order->set_status( 'completed' );

		$this->expectException( IneligibleActionException::class );

		$this->cancel->execute( $order, 'other', '', Request::SOURCE_ACCOUNT, 1 );
	}

	/**
	 * The default expected response time is 24 hours.
	 *
	 * @return void
	 */
	public function test_default_response_time_is_24_hours(): void {
		$this->assertSame( 24, Cancel::response_time_hours() );
	}

	/**
	 * The configured response time is read from settings, floored at 1 hour.
	 *
	 * @return void
	 */
	public function test_response_time_is_configurable_and_floored(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESPONSE_TIME_SETTING => 0 );

		$this->assertSame( 1, Cancel::response_time_hours() );

		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESPONSE_TIME_SETTING => 48 );

		$this->assertSame( 48, Cancel::response_time_hours() );
	}

	/**
	 * Approve() transitions the order, writes a note, and never calls a
	 * refund function.
	 *
	 * @return void
	 */
	public function test_approve_transitions_the_order_and_writes_a_note(): void {
		$order = new \WC_Order( 5, 'processing' );

		$transitioned = $this->cancel->approve( $order, 3 );

		$this->assertTrue( $transitioned );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertCount( 1, $order->notes );
		$this->assertFalse( $order->notes[0]['is_customer_note'], 'The approval note is merchant-only.' );
		$this->assertSame( 0, FakeWordPress::$refund_calls, 'Approve() must never call a refund function.' );
	}

	/**
	 * Restocking is on by default, matching WooCommerce's own refund-screen default.
	 *
	 * @return void
	 */
	public function test_approve_restocks_by_default(): void {
		$order = new \WC_Order( 5, 'processing' );

		$this->cancel->approve( $order, 3 );

		$this->assertSame( array( 5 ), FakeWordPress::$restocked_orders );
	}

	/**
	 * Restocking is skipped when the setting is explicitly off.
	 *
	 * @return void
	 */
	public function test_approve_does_not_restock_when_the_setting_is_off(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESTOCK_SETTING => false );

		$order = new \WC_Order( 5, 'processing' );

		$this->cancel->approve( $order, 3 );

		$this->assertSame( array(), FakeWordPress::$restocked_orders );
	}

	/**
	 * An order already cancelled is left alone: no second transition, no
	 * restock, no note, and the caller learns nothing happened.
	 *
	 * @return void
	 */
	public function test_approve_does_nothing_to_an_order_already_cancelled(): void {
		$order = new \WC_Order( 5, 'cancelled' );

		$transitioned = $this->cancel->approve( $order, 3 );

		$this->assertFalse( $transitioned );
		$this->assertSame( 0, $order->status_transitions );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), FakeWordPress::$restocked_orders );
	}
}
