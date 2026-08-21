<?php
/**
 * ActionRegistry unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionRegistry;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers registration, lookup and context filtering — the extension point
 * itself. No concrete action registers against this yet (Cancel and Reorder
 * are later milestones), so every case here uses a synthetic test action.
 *
 * @since 0.7.0
 *
 * @covers \PostPurchaseHub\Actions\ActionRegistry
 */
final class ActionRegistryTest extends TestCase {

	/**
	 * A registered action resolves back with its own id, label and contexts.
	 *
	 * @return void
	 */
	public function test_it_returns_a_registered_action(): void {
		$registry = new ActionRegistry();

		$registry->register(
			'cancel',
			'Request cancellation',
			array( 'list', 'detail' ),
			static function (): ?array {
				return null;
			}
		);

		$action = $registry->get( 'cancel' );

		$this->assertNotNull( $action );
		$this->assertSame( 'cancel', $action->id );
		$this->assertSame( 'Request cancellation', $action->label );
		$this->assertSame( array( 'list', 'detail' ), $action->contexts );
	}

	/**
	 * An unregistered id returns null rather than a missing-key error.
	 *
	 * @return void
	 */
	public function test_an_unknown_id_returns_null(): void {
		$registry = new ActionRegistry();

		$this->assertNull( $registry->get( 'nope' ) );
	}

	/**
	 * Registering the same id twice replaces the first registration.
	 *
	 * @return void
	 */
	public function test_registering_the_same_id_again_replaces_it(): void {
		$registry = new ActionRegistry();

		$registry->register( 'cancel', 'First', array( 'list' ), static fn (): ?array => null );
		$registry->register( 'cancel', 'Second', array( 'detail' ), static fn (): ?array => null );

		$action = $registry->get( 'cancel' );

		$this->assertSame( 'Second', $action->label );
		$this->assertSame( array( 'detail' ), $action->contexts );
	}

	/**
	 * An unrecognised context is rejected at registration time.
	 *
	 * @return void
	 */
	public function test_an_unknown_context_is_rejected(): void {
		$registry = new ActionRegistry();

		$this->expectException( \InvalidArgumentException::class );

		$registry->register( 'cancel', 'Cancel', array( 'sidebar' ), static fn (): ?array => null );
	}

	/**
	 * For_context() returns only the actions that declared that context, in
	 * registration order.
	 *
	 * @return void
	 */
	public function test_for_context_filters_and_preserves_order(): void {
		$registry = new ActionRegistry();

		$registry->register( 'cancel', 'Cancel', array( 'list', 'detail' ), static fn (): ?array => null );
		$registry->register( 'invoice', 'Invoice', array( 'detail' ), static fn (): ?array => null );
		$registry->register( 'help', 'Help', array( 'list' ), static fn (): ?array => null );

		$detail_ids = array_map(
			static fn ( $action ) => $action->id,
			$registry->for_context( 'detail' )
		);

		$this->assertSame( array( 'cancel', 'invoice' ), $detail_ids );
	}

	/**
	 * All() returns every registration, regardless of context.
	 *
	 * @return void
	 */
	public function test_all_returns_every_registration(): void {
		$registry = new ActionRegistry();

		$registry->register( 'cancel', 'Cancel', array( 'list' ), static fn (): ?array => null );
		$registry->register( 'help', 'Help', array( 'detail' ), static fn (): ?array => null );

		$this->assertCount( 2, $registry->all() );
	}

	/**
	 * RegisteredAction::resolve() is the "action executor" this milestone's
	 * enforcement tests target directly: an ineligible order gets null back
	 * from calling it directly, with no UI in the path at all.
	 *
	 * @return void
	 */
	public function test_the_action_executor_can_be_called_directly_and_denies(): void {
		$registry = new ActionRegistry();

		$registry->register(
			'cancel',
			'Cancel',
			array( 'list', 'detail' ),
			static function ( \WC_Order $order ): ?array {
				return 'pending' === $order->get_status()
					? array(
						'name' => 'Cancel',
						'url'  => '#',
					)
					: null;
			}
		);

		$action = $registry->get( 'cancel' );

		$this->assertNull( $action->resolve( new \WC_Order( 1, 'completed' ), 'list' ) );
		$this->assertNotNull( $action->resolve( new \WC_Order( 1, 'pending' ), 'list' ) );
	}
}
