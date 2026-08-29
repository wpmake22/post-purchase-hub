<?php
/**
 * RequestActionController unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Admin\RequestActionController;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestLifecycle;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\WPDieException;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the one thing this milestone must get right above all else:
 * capability, then nonce, then action — with never a refund call anywhere
 * in the path — plus idempotency and the reconciliation branch.
 *
 * @since 0.9.0
 *
 * @covers \PostPurchaseHub\Admin\RequestActionController
 */
final class RequestActionControllerTest extends TestCase {

	/**
	 * Request-resolution double.
	 *
	 * @var FakeRequestResolution
	 */
	private FakeRequestResolution $requests;

	/**
	 * Real Cancel, over a fake lifecycle — its approve() is exercised for real
	 * against the \WC_Order stub.
	 *
	 * @var Cancel
	 */
	private Cancel $cancel;

	/**
	 * Controller under test.
	 *
	 * @var RequestActionController
	 */
	private RequestActionController $controller;

	/**
	 * Builds the controller over fresh doubles, with the capability granted
	 * and a valid nonce, so each test only has to override what it is testing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		FakeWordPress::$current_user_capabilities = array( RequestActionController::CAPABILITY );

		$this->requests   = new FakeRequestResolution();
		$this->cancel     = new Cancel( new EligibilityResolver( new FakeRequestHistory() ), new FakeRequestLifecycle() );
		$this->controller = new RequestActionController( $this->requests, $this->cancel, new Logger() );
	}

	/**
	 * A valid submission's params.
	 *
	 * @param int $request_id Request id.
	 * @return array<string, mixed>
	 */
	private static function params( int $request_id ): array {
		return array(
			'request_id' => (string) $request_id,
			'_wpnonce'   => wp_create_nonce( RequestActionController::NONCE_ACTION ),
		);
	}

	/**
	 * A user lacking the capability is rejected before anything else runs —
	 * not merely a hidden button.
	 *
	 * @return void
	 */
	public function test_approve_rejects_a_user_without_the_capability(): void {
		FakeWordPress::$current_user_capabilities = array();

		$request                  = $this->requests->seed( array( 'order_id' => 1 ) );
		FakeWordPress::$orders[1] = new \WC_Order( 1, 'processing' );

		try {
			$this->controller->approve( self::params( $request->id ) );
			$this->fail( 'Expected a 403.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
		}

		$this->assertSame( array(), $this->requests->approved, 'Capability is checked before the nonce or the action.' );
	}

	/**
	 * Decline is rejected the same way.
	 *
	 * @return void
	 */
	public function test_decline_rejects_a_user_without_the_capability(): void {
		FakeWordPress::$current_user_capabilities = array();

		$request = $this->requests->seed( array( 'order_id' => 1 ) );

		try {
			$this->controller->decline( self::params( $request->id ) );
			$this->fail( 'Expected a 403.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
		}

		$this->assertSame( array(), $this->requests->declined );
	}

	/**
	 * A missing or stale nonce is rejected even for a fully-capable user.
	 *
	 * @return void
	 */
	public function test_approve_rejects_a_stale_nonce(): void {
		$request                  = $this->requests->seed( array( 'order_id' => 1 ) );
		FakeWordPress::$orders[1] = new \WC_Order( 1, 'processing' );

		try {
			$this->controller->approve(
				array(
					'request_id' => (string) $request->id,
					'_wpnonce'   => 'not-a-real-nonce',
				)
			);
			$this->fail( 'Expected a 403.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
		}

		$this->assertSame( array(), $this->requests->approved );
	}

	/**
	 * A missing nonce is treated the same as a wrong one.
	 *
	 * @return void
	 */
	public function test_approve_rejects_a_missing_nonce(): void {
		$request                  = $this->requests->seed( array( 'order_id' => 1 ) );
		FakeWordPress::$orders[1] = new \WC_Order( 1, 'processing' );

		try {
			$this->controller->approve( array( 'request_id' => (string) $request->id ) );
			$this->fail( 'Expected a 403.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
		}

		$this->assertSame( array(), $this->requests->approved );
	}

	/**
	 * The happy path: the order transitions, the request is marked approved,
	 * and no refund function is ever called.
	 *
	 * @return void
	 */
	public function test_approve_transitions_the_order_and_marks_the_request_approved(): void {
		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		FakeWordPress::$current_user_id = 9;

		$this->controller->approve( self::params( $request->id ) );

		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertCount( 1, $this->requests->approved );
		$this->assertSame( Request::STATUS_APPROVED, $this->requests->requests[ $request->id ]->status );
		$this->assertSame( 9, $this->requests->approved[0]['resolved_by'] );
		$this->assertSame( 0, FakeWordPress::$refund_calls, 'Approving must never call a refund function.' );
		$this->assertCount( 1, FakeWordPress::$redirects, 'A successful submission redirects.' );
	}

	/**
	 * The request is resolved before the order transitions, so the moment the
	 * transition fires woocommerce_order_status_changed, nothing is left
	 * pending for a reconciliation listener to close a second time. The
	 * approved status recorded by the double proves the ordering held.
	 *
	 * @return void
	 */
	public function test_approve_resolves_the_request_before_transitioning_the_order(): void {
		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->approve( self::params( $request->id ) );

		$this->assertSame( Request::STATUS_APPROVED, $this->requests->requests[ $request->id ]->status );
		$this->assertSame( array(), $this->requests->completed, 'A normal approval must never also be recorded as a reconciliation.' );
	}

	/**
	 * Restocking follows the setting: on by default.
	 *
	 * @return void
	 */
	public function test_approve_restocks_by_default(): void {
		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->approve( self::params( $request->id ) );

		$this->assertSame( array( 5 ), FakeWordPress::$restocked_orders );
	}

	/**
	 * Restocking is skipped when the setting is off.
	 *
	 * @return void
	 */
	public function test_approve_does_not_restock_when_the_setting_is_off(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESTOCK_SETTING => false );

		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->approve( self::params( $request->id ) );

		$this->assertSame( array(), FakeWordPress::$restocked_orders );
	}

	/**
	 * Double-submitting approve is idempotent: the second submission finds
	 * the request no longer open and does nothing further.
	 *
	 * @return void
	 */
	public function test_approve_is_idempotent_against_double_submit(): void {
		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->approve( self::params( $request->id ) );
		$this->controller->approve( self::params( $request->id ) );

		$this->assertCount( 1, $this->requests->approved, 'A second submission must not resolve the request again.' );
		$this->assertSame( 1, $order->status_transitions, 'A second submission must not transition the order again.' );
	}

	/**
	 * An order already cancelled by another route closes the request as
	 * completed, with no duplicate transition and no restock.
	 *
	 * @return void
	 */
	public function test_approve_reconciles_instead_of_transitioning_an_already_cancelled_order(): void {
		$order                    = new \WC_Order( 5, 'cancelled' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->approve( self::params( $request->id ) );

		$this->assertSame( array(), $this->requests->approved );
		$this->assertCount( 1, $this->requests->completed );
		$this->assertSame( Request::STATUS_COMPLETED, $this->requests->requests[ $request->id ]->status );
		$this->assertSame( 0, $order->status_transitions );
		$this->assertSame( array(), FakeWordPress::$restocked_orders );
		$this->assertSame( 0, FakeWordPress::$refund_calls );
	}

	/**
	 * Decline never touches the order.
	 *
	 * @return void
	 */
	public function test_decline_never_touches_the_order(): void {
		$order                    = new \WC_Order( 5, 'processing' );
		FakeWordPress::$orders[5] = $order;
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->decline( self::params( $request->id ) );

		$this->assertCount( 1, $this->requests->declined );
		$this->assertSame( Request::STATUS_DECLINED, $this->requests->requests[ $request->id ]->status );
		$this->assertSame( 0, $order->status_transitions );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), FakeWordPress::$restocked_orders );
		$this->assertSame( 0, FakeWordPress::$refund_calls );
	}

	/**
	 * Double-submitting decline is idempotent too.
	 *
	 * @return void
	 */
	public function test_decline_is_idempotent_against_double_submit(): void {
		FakeWordPress::$orders[5] = new \WC_Order( 5, 'processing' );
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$this->controller->decline( self::params( $request->id ) );
		$this->controller->decline( self::params( $request->id ) );

		$this->assertCount( 1, $this->requests->declined );
	}

	/**
	 * An admin note on the form is sanitised and stored; an empty one is
	 * stored as null rather than an empty string.
	 *
	 * @return void
	 */
	public function test_admin_note_is_sanitised_and_empty_is_stored_as_null(): void {
		FakeWordPress::$orders[5] = new \WC_Order( 5, 'processing' );
		$request                  = $this->requests->seed( array( 'order_id' => 5 ) );

		$params               = self::params( $request->id );
		$params['admin_note'] = '<script>alert(1)</script>Looks legitimate.';

		$this->controller->approve( $params );

		$this->assertSame( 'Looks legitimate.', $this->requests->approved[0]['admin_note'] );

		$request2                 = $this->requests->seed( array( 'order_id' => 6 ) );
		FakeWordPress::$orders[6] = new \WC_Order( 6, 'processing' );

		$params2               = self::params( $request2->id );
		$params2['admin_note'] = '';

		$this->controller->approve( $params2 );

		$this->assertCount( 2, $this->requests->approved );
		$this->assertNull( $this->requests->approved[1]['admin_note'] );
	}

	/**
	 * An unknown request id is a silent no-op, not a fatal.
	 *
	 * @return void
	 */
	public function test_approve_does_nothing_for_an_unknown_request_id(): void {
		$this->controller->approve(
			array(
				'request_id' => '999',
				'_wpnonce'   => wp_create_nonce( RequestActionController::NONCE_ACTION ),
			)
		);

		$this->assertSame( array(), $this->requests->approved );
		$this->assertCount( 1, FakeWordPress::$redirects, 'Even a no-op submission redirects back to the queue.' );
	}
}
