<?php
/**
 * Where actions register themselves.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * An in-memory list of actions, keyed by id.
 *
 * Nothing registers here yet: this class is the extension point, not a
 * feature. Cancel, Reorder, Invoice and Help each register themselves against
 * it in their own milestones — this milestone builds the point they attach
 * to, and the eligibility engine every one of them will call.
 *
 * Deliberately not a service locator for anything but this one kind of
 * object: register a factory in `Services` for anything an action's resolver
 * needs to depend on.
 *
 * @since 0.7.0
 */
final class ActionRegistry {

	/**
	 * Contexts an action may declare.
	 *
	 * @var string[]
	 */
	public const CONTEXTS = array( 'list', 'detail' );

	/**
	 * Registered actions, keyed by id.
	 *
	 * @var array<string, RegisteredAction>
	 */
	private array $actions = array();

	/**
	 * Registers an action, replacing any previous registration under the same id.
	 *
	 * Replacing rather than rejecting a duplicate id is deliberate: it is what
	 * lets Pro, or a filter-driven extension, override a core action's
	 * behaviour by re-registering its id.
	 *
	 * @since 0.7.0
	 *
	 * @param string   $id       Stable action id, e.g. `cancel`.
	 * @param string   $label    Human-readable label, already translated by the caller.
	 * @param array    $contexts Contexts this action renders in. Must be a non-empty subset of CONTEXTS.
	 * @param \Closure $resolver Eligibility + render payload for one order and context.
	 *
	 * @phpstan-param list<string> $contexts
	 * @phpstan-param \Closure(\WC_Order, string): (array<string, mixed>|null) $resolver
	 *
	 * @return void
	 * @throws \InvalidArgumentException When a context is not one of CONTEXTS.
	 */
	public function register( string $id, string $label, array $contexts, \Closure $resolver ): void {
		foreach ( $contexts as $context ) {
			if ( ! in_array( $context, self::CONTEXTS, true ) ) {
				// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
				throw new \InvalidArgumentException( esc_html( 'Unknown action context: ' . (string) $context ) );
			}
		}

		$this->actions[ $id ] = new RegisteredAction( $id, $label, array_values( $contexts ), $resolver );
	}

	/**
	 * Returns one registered action, if any.
	 *
	 * @since 0.7.0
	 *
	 * @param string $id Action id.
	 * @return RegisteredAction|null
	 */
	public function get( string $id ): ?RegisteredAction {
		return $this->actions[ $id ] ?? null;
	}

	/**
	 * Returns every registered action, in registration order.
	 *
	 * @since 0.7.0
	 *
	 * @return list<RegisteredAction>
	 */
	public function all(): array {
		return array_values( $this->actions );
	}

	/**
	 * Returns the registered actions that declare a given context.
	 *
	 * @since 0.7.0
	 *
	 * @param string $context Context to filter by.
	 * @return list<RegisteredAction>
	 */
	public function for_context( string $context ): array {
		return array_values(
			array_filter(
				$this->actions,
				static function ( RegisteredAction $action ) use ( $context ): bool {
					return $action->applies_to( $context );
				}
			)
		);
	}
}
