<?php
/**
 * One action as known to the registry.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Static metadata plus the one callable that decides everything dynamic.
 *
 * `resolver` is the action's own eligibility check and render payload in one:
 * given an order and the context it is being drawn for, it returns `null`
 * when the action does not apply, or the render array a caller injects into
 * an actions list otherwise. It is also the "action executor" this
 * milestone's tests call directly — invoking it is exactly what any future
 * REST controller or renderer does, so a test calling it IS testing
 * server-side enforcement, not the UI.
 *
 * @since 0.7.0
 */
final class RegisteredAction {

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param string   $id       Stable action id, e.g. `cancel`.
	 * @param string   $label    Human-readable label, translated by the caller.
	 * @param array    $contexts Contexts this action may render in: `list`, `detail`.
	 * @param \Closure $resolver Eligibility + render payload for one order and context.
	 *
	 * @phpstan-param list<string> $contexts
	 * @phpstan-param \Closure(\WC_Order, string): (array<string, mixed>|null) $resolver
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly array $contexts,
		public readonly \Closure $resolver
	) {}

	/**
	 * Whether this action may render in a given context.
	 *
	 * @since 0.7.0
	 *
	 * @param string $context Context to check.
	 * @return bool
	 */
	public function applies_to( string $context ): bool {
		return in_array( $context, $this->contexts, true );
	}

	/**
	 * Runs the resolver for one order and context.
	 *
	 * @since 0.7.0
	 *
	 * @param \WC_Order $order   Order to resolve against.
	 * @param string    $context Context being rendered.
	 * @return array<string, mixed>|null
	 */
	public function resolve( \WC_Order $order, string $context ): ?array {
		$result = ( $this->resolver )( $order, $context );

		return is_array( $result ) ? $result : null;
	}
}
