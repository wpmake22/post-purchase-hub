<?php
/**
 * Ownership-denial exception.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * Thrown by `OwnershipResolver::assertCanAccess()` on every failure path.
 *
 * One exception type for every reason: a caller catching this can show a
 * generic denial without a `switch` over reasons that would otherwise tempt
 * someone into surfacing "order not found" versus "not yours" to the visitor,
 * which is exactly the existence oracle Phase 8 forbids. `reason_code` exists
 * for logs and tests, not for the response.
 *
 * @since 0.6.0
 */
final class AccessDeniedException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param string $reason_code Internal reason, for logs and tests only.
	 * @param int    $order_id    Order the caller attempted to reach.
	 * @param string $context     Caller-supplied context, e.g. 'rest:requests.create'.
	 */
	public function __construct(
		public readonly string $reason_code,
		public readonly int $order_id,
		public readonly string $context
	) {
		// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
		parent::__construct( esc_html( 'Access denied: ' . $reason_code ) );
	}
}
