<?php
/**
 * Timeline presenter unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Frontend\TimelineView;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\Timeline;
use PostPurchaseHub\Timeline\TimelineStage;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the strings a template echoes, including the ones that carry meaning
 * colour alone must not.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\TimelineView
 */
final class TimelineViewTest extends TestCase {

	/**
	 * Clears the fake WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Builds a timeline from stage specifications.
	 *
	 * @param array<int, array{string, string, string|null}> $stages     Key, state, timestamp.
	 * @param TimelineStage|null                             $branch     Branch state.
	 * @param bool                                           $historical Whether dates are unavailable.
	 * @return Timeline
	 */
	private function timeline( array $stages, ?TimelineStage $branch = null, bool $historical = false ): Timeline {
		$built = array();

		foreach ( $stages as $stage ) {
			$built[] = new TimelineStage( $stage[0], ucfirst( $stage[0] ), $stage[1], $stage[2], null );
		}

		return new Timeline( 42, 'processing', $built, $branch, $historical );
	}

	/**
	 * Every stage carries a state word, so state is never colour alone.
	 *
	 * @return void
	 */
	public function test_every_stage_carries_a_text_state(): void {
		$view = TimelineView::present(
			$this->timeline(
				array(
					array( StageMap::PLACED, TimelineStage::STATE_COMPLETE, '2026-03-01 09:00:00' ),
					array( StageMap::CONFIRMED, TimelineStage::STATE_CURRENT, '2026-03-02 09:00:00' ),
					array( StageMap::DELIVERED, TimelineStage::STATE_PENDING, null ),
				)
			)
		);

		$this->assertSame( array( 'Done', 'In progress', 'Not yet' ), array_column( $view['stages'], 'state_label' ) );

		foreach ( $view['stages'] as $stage ) {
			$this->assertNotSame( '', $stage['state_label'] );
			$this->assertNotSame( '', $stage['label'] );
		}
	}

	/**
	 * A stored UTC timestamp becomes a machine datetime and a readable label.
	 *
	 * @return void
	 */
	public function test_timestamps_become_datetime_and_label(): void {
		FakeWordPress::$options['date_format'] = 'Y-m-d';
		FakeWordPress::$options['time_format'] = 'H:i';

		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_CURRENT, '2026-03-01 09:30:00' ) ) )
		);

		$this->assertSame( '2026-03-01T09:30:00+00:00', $view['stages'][0]['datetime'] );
		$this->assertSame( '2026-03-01 09:30', $view['stages'][0]['date_label'] );
	}

	/**
	 * A stage with no timestamp says nothing rather than something wrong.
	 *
	 * @return void
	 */
	public function test_a_stage_without_a_timestamp_carries_no_date(): void {
		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::DELIVERED, TimelineStage::STATE_PENDING, null ) ) )
		);

		$this->assertSame( '', $view['stages'][0]['datetime'] );
		$this->assertSame( '', $view['stages'][0]['date_label'] );
	}

	/**
	 * A historical order gets an explanation instead of blank dates.
	 *
	 * @return void
	 */
	public function test_a_historical_timeline_carries_a_notice(): void {
		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::DELIVERED, TimelineStage::STATE_CURRENT, null ) ), null, true )
		);

		$this->assertTrue( $view['historical'] );
		$this->assertNotSame( '', $view['notice'] );
	}

	/**
	 * A live order is not apologised for.
	 *
	 * @return void
	 */
	public function test_a_dated_timeline_carries_no_notice(): void {
		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_CURRENT, '2026-03-01 09:00:00' ) ) )
		);

		$this->assertSame( '', $view['notice'] );
	}

	/**
	 * The branch state is presented, and is what "current" reports.
	 *
	 * @return void
	 */
	public function test_a_branch_state_is_presented_as_current(): void {
		$branch = new TimelineStage( StageMap::CANCELLED, 'Cancelled', TimelineStage::STATE_CURRENT, '2026-03-03 12:00:00', 'cancelled' );

		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_COMPLETE, '2026-03-01 09:00:00' ) ), $branch )
		);

		$this->assertNotNull( $view['branch'] );
		$this->assertSame( StageMap::CANCELLED, $view['branch']['key'] );
		$this->assertNotNull( $view['current'] );
		$this->assertSame( StageMap::CANCELLED, $view['current']['key'] );
	}

	/**
	 * The date format is filterable, because a merchant may want it terser.
	 *
	 * @return void
	 */
	public function test_the_date_format_is_filterable(): void {
		add_filter(
			'pph_timeline_date_format',
			static function (): string {
				return 'D';
			}
		);

		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_CURRENT, '2026-03-01 09:00:00' ) ) )
		);

		$this->assertSame( 'Sun', $view['stages'][0]['date_label'] );
	}

	/**
	 * With no real branch, a pending-cancellation overlay becomes the branch
	 * shown, including the note a real branch never carries.
	 *
	 * @return void
	 */
	public function test_a_pending_cancellation_overlay_becomes_the_branch_when_there_is_none(): void {
		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::CONFIRMED, TimelineStage::STATE_CURRENT, '2026-03-01 09:00:00' ) ) ),
			array(
				'label'         => 'Cancellation requested',
				'timestamp_utc' => '2026-03-02 10:00:00',
				'note'          => 'We usually respond within 1 day.',
			)
		);

		$this->assertNotNull( $view['branch'] );
		$this->assertSame( 'cancellation_requested', $view['branch']['key'] );
		$this->assertSame( 'Cancellation requested', $view['branch']['label'] );
		$this->assertSame( TimelineStage::STATE_CURRENT, $view['branch']['state'] );
		$this->assertSame( 'We usually respond within 1 day.', $view['branch_note'] );
		$this->assertNotNull( $view['current'] );
		$this->assertSame( 'cancellation_requested', $view['current']['key'] );
	}

	/**
	 * A real branch always wins: an order genuinely cancelled or refunded
	 * does not also show a still-pending request.
	 *
	 * @return void
	 */
	public function test_a_real_branch_is_not_overridden_by_the_pending_overlay(): void {
		$branch = new TimelineStage( StageMap::CANCELLED, 'Cancelled', TimelineStage::STATE_CURRENT, '2026-03-03 12:00:00', 'cancelled' );

		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_COMPLETE, '2026-03-01 09:00:00' ) ), $branch ),
			array(
				'label'         => 'Cancellation requested',
				'timestamp_utc' => '2026-03-02 10:00:00',
				'note'          => 'We usually respond within 1 day.',
			)
		);

		$this->assertSame( StageMap::CANCELLED, $view['branch']['key'] );
		$this->assertSame( '', $view['branch_note'], 'A real branch carries no pending-request note.' );
	}

	/**
	 * With no overlay supplied, branch_note stays empty and nothing about
	 * existing callers changes.
	 *
	 * @return void
	 */
	public function test_branch_note_is_empty_with_no_overlay(): void {
		$view = TimelineView::present(
			$this->timeline( array( array( StageMap::PLACED, TimelineStage::STATE_CURRENT, '2026-03-01 09:00:00' ) ) )
		);

		$this->assertSame( '', $view['branch_note'] );
	}
}
