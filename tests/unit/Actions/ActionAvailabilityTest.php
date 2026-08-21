<?php
/**
 * Action availability unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\EligibilityResult;
use PostPurchaseHub\Actions\EligibilityRule;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Actions\IneligibleActionException;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * A merchant's on/off switch has to be real, which means refused at the point
 * of execution and not merely undrawn — the failure mode docs/SPEC.md Phase 8
 * keeps returning to ("ineligibility is enforced server-side, not just hidden
 * in the UI").
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Actions\ActionAvailability
 * @covers \PostPurchaseHub\Actions\EligibilityResolver
 */
final class ActionAvailabilityTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$current_user_id = 4;
	}

	/**
	 * Switches one action off through the settings option.
	 *
	 * @param string $action_id Action to disable.
	 * @return void
	 */
	private function disable( string $action_id ): void {
		FakeWordPress::$options['pph_settings'] = array(
			ActionAvailability::SETTING => array( $action_id => false ),
		);
	}

	/**
	 * An order the actions would otherwise apply to.
	 *
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		$order = new \WC_Order( 6100, 'processing' );
		$order->set_customer_id( 4 );
		$order->set_billing_email( 'customer@example.test' );

		FakeWordPress::$orders[6100] = $order;

		return $order;
	}

	/**
	 * An unconfigured store has every action on: a store upgrading into a build
	 * that adds one gets it, rather than silently not getting it.
	 *
	 * @return void
	 */
	public function test_actions_are_on_by_default(): void {
		foreach ( array_keys( ActionAvailability::DEFAULTS ) as $action_id ) {
			$this->assertTrue( ActionAvailability::is_enabled( $action_id ) );
		}
	}

	/**
	 * Only an explicit false turns something off.
	 *
	 * @return void
	 */
	public function test_only_an_explicit_false_disables(): void {
		$this->disable( Reorder::ID );

		$this->assertFalse( ActionAvailability::is_enabled( Reorder::ID ) );
		$this->assertTrue( ActionAvailability::is_enabled( Cancel::ID ) );
	}

	/**
	 * A disabled action is denied by the resolver every action shares, with a
	 * stable reason code.
	 *
	 * @return void
	 */
	public function test_the_resolver_denies_a_disabled_action(): void {
		$this->disable( Cancel::ID );

		$resolver = new EligibilityResolver( new FakeRequestHistory() );
		$result   = $resolver->resolve( Cancel::ID, $this->order(), new EligibilityRule() );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_ACTION_DISABLED, $result->reason_code );
	}

	/**
	 * The switch is asked before anything expensive: a disabled action does not
	 * even reach the request-history lookup.
	 *
	 * @return void
	 */
	public function test_a_disabled_action_costs_no_storage_lookup(): void {
		$this->disable( Cancel::ID );

		$history  = new FakeRequestHistory();
		$resolver = new EligibilityResolver( $history );

		$resolver->resolve(
			Cancel::ID,
			$this->order(),
			new EligibilityRule( per_order_cap: 1, cooldown_seconds: 3600 )
		);

		$this->assertSame( 0, $history->count_calls, 'The cheapest check comes first.' );
	}

	/**
	 * The action itself refuses to execute, which is what makes the switch real
	 * for a client that forged the button back into the page.
	 *
	 * @return void
	 */
	public function test_a_disabled_action_refuses_to_execute(): void {
		$this->disable( Help::ID );

		$stages   = new StageMap( new StatusDetector( new Cache() ) );
		$timeline = new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) );
		$help     = new Help( new EligibilityResolver( new FakeRequestHistory() ), new HelpContextBuilder( $timeline ) );

		$order = $this->order();

		$this->assertNull( $help->resolve( $order, 'detail' ), 'The button is gone.' );

		$this->expectException( IneligibleActionException::class );

		$help->submit( $order, 'other', 'Hello', Help::SOURCE_ACCOUNT );
	}

	/**
	 * The filter can make availability conditional on something this plugin
	 * does not model.
	 *
	 * @return void
	 */
	public function test_the_filter_can_disable_an_action(): void {
		FakeWordPress::$filters['pph_action_enabled'][] = static function ( $enabled, $action_id ) {
			return Reorder::ID === $action_id ? false : $enabled;
		};

		$this->assertFalse( ActionAvailability::is_enabled( Reorder::ID ) );
		$this->assertTrue( ActionAvailability::is_enabled( Cancel::ID ) );
	}

	/**
	 * A corrupted settings value reads as "everything on" rather than fataling
	 * or silently removing every action.
	 *
	 * @return void
	 */
	public function test_a_corrupted_setting_leaves_actions_on(): void {
		FakeWordPress::$options['pph_settings'] = array( ActionAvailability::SETTING => 'nonsense' );

		$this->assertTrue( ActionAvailability::is_enabled( Cancel::ID ) );
		$this->assertSame( ActionAvailability::DEFAULTS, ActionAvailability::all() );
	}

	/**
	 * Labels come from the actions themselves, so a renamed button cannot end
	 * up with two names.
	 *
	 * @return void
	 */
	public function test_labels_come_from_the_actions(): void {
		$labels = ActionAvailability::labels();

		$this->assertSame( Cancel::label(), $labels[ Cancel::ID ] );
		$this->assertSame( Reorder::label(), $labels[ Reorder::ID ] );
		$this->assertSame( array_keys( ActionAvailability::DEFAULTS ), array_keys( $labels ) );
		$this->assertSame( array_keys( ActionAvailability::DEFAULTS ), array_keys( ActionAvailability::descriptions() ) );
	}
}
