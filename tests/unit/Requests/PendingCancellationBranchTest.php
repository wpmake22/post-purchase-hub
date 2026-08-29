<?php
/**
 * PendingCancellationBranch unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Tests\Unit\Actions\FakeRequestHistory;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the detail-page-only "Cancellation requested" overlay: present only
 * for a pending request, never for one already resolved.
 *
 * @since 0.8.0
 *
 * @covers \PostPurchaseHub\Requests\PendingCancellationBranch
 */
final class PendingCancellationBranchTest extends TestCase {

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
	 * No request at all: nothing to overlay.
	 *
	 * @return void
	 */
	public function test_null_when_there_is_no_request(): void {
		$branch = new PendingCancellationBranch( new FakeRequestHistory() );

		$this->assertNull( $branch->for_order( new \WC_Order( 1, 'processing' ) ) );
	}

	/**
	 * A pending cancellation request produces the overlay.
	 *
	 * @return void
	 */
	public function test_a_pending_request_produces_the_overlay(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertNotNull( $overlay );
		$this->assertSame( 'Cancellation requested', $overlay['label'] );
		$this->assertSame( '2026-01-01 12:00:00', $overlay['timestamp_utc'] );
	}

	/**
	 * A request that has already been resolved does not produce the overlay.
	 *
	 * @dataProvider resolved_statuses
	 *
	 * @param string $status Non-pending status.
	 * @return void
	 */
	public function test_a_resolved_request_produces_no_overlay( string $status ): void {
		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', $status );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertNull( $overlay );
	}

	/**
	 * Non-pending statuses a resolved request might carry.
	 *
	 * @return array<string, array{string}>
	 */
	public static function resolved_statuses(): array {
		return array(
			'approved'  => array( Request::STATUS_APPROVED ),
			'declined'  => array( Request::STATUS_DECLINED ),
			'withdrawn' => array( Request::STATUS_WITHDRAWN ),
			'completed' => array( Request::STATUS_COMPLETED ),
		);
	}

	/**
	 * The overlay is scoped to cancellation requests specifically.
	 *
	 * @return void
	 */
	public function test_a_pending_request_of_another_type_produces_no_overlay(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_HELP, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertNull( $overlay );
	}

	/**
	 * The overlay is scoped per order.
	 *
	 * @return void
	 */
	public function test_the_overlay_does_not_leak_across_orders(): void {
		$history = new FakeRequestHistory();
		$history->add( 999, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertNull( $overlay );
	}

	/**
	 * The note names the default 24-hour response time.
	 *
	 * @return void
	 */
	public function test_the_note_uses_the_default_response_time(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertSame( 'We usually respond within 1 day.', $overlay['note'] );
	}

	/**
	 * The note reflects a configured response time in hours when it does not
	 * divide evenly into days.
	 *
	 * @return void
	 */
	public function test_the_note_uses_hours_when_not_a_whole_number_of_days(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESPONSE_TIME_SETTING => 6 );

		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertSame( 'We usually respond within 6 hours.', $overlay['note'] );
	}

	/**
	 * The note reflects a configured response time in whole days.
	 *
	 * @return void
	 */
	public function test_the_note_uses_days_when_configured_in_whole_days(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( Cancel::RESPONSE_TIME_SETTING => 48 );

		$history = new FakeRequestHistory();
		$history->add( 1, Request::TYPE_CANCELLATION, '2026-01-01 12:00:00', Request::STATUS_PENDING );

		$overlay = ( new PendingCancellationBranch( $history ) )->for_order( new \WC_Order( 1, 'processing' ) );

		$this->assertSame( 'We usually respond within 2 days.', $overlay['note'] );
	}
}
