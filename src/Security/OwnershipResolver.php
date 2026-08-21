<?php
/**
 * The one place ownership of an order is decided.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * Decides whether the current request may reach a given order.
 *
 * This is the ONLY ownership check in the codebase (CLAUDE.md hard rule 2).
 * Every REST controller, shortcode render and template call goes through
 * `assertCanAccess()` instead of comparing `get_customer_id()` inline —
 * enforced by the `Ownership choke point` CI gate, which fails the build on
 * any `get_customer_id()` reference outside this file.
 *
 * Exactly three identities can pass: the order's own customer, a bearer of a
 * valid signed token for this order, or a user who can `edit_shop_orders`.
 * Anything else throws before the caller learns anything about the order —
 * including, on the "order not found" path, whether it exists at all.
 *
 * @since 0.6.0
 */
final class OwnershipResolver {

	/**
	 * Capability that grants staff access regardless of ownership.
	 *
	 * @var string
	 */
	public const STAFF_CAPABILITY = 'edit_shop_orders';

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param TokenService $tokens Verifies the signed-token identity.
	 */
	public function __construct( private TokenService $tokens ) {}

	/**
	 * Returns the order if the current request is entitled to reach it.
	 *
	 * @since 0.6.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $context  Caller-supplied label for logs and tests, e.g. 'rest:requests.create'.
	 * @return \WC_Order
	 * @throws AccessDeniedException When none of the three identities apply.
	 */
	public function assertCanAccess( int $order_id, string $context ): \WC_Order { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method name fixed by docs/SPEC.md Phase 8.
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor args stored as properties, not the message; AccessDeniedException escapes its own message.
			throw new AccessDeniedException( 'order_not_found', $order_id, $context );
		}

		if ( $this->current_user_owns( $order ) ) {
			return $order;
		}

		if ( $this->bearer_token_matches( $order ) ) {
			return $order;
		}

		if ( current_user_can( self::STAFF_CAPABILITY ) ) {
			return $order;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor args stored as properties, not the message; AccessDeniedException escapes its own message.
		throw new AccessDeniedException( 'not_authorised', $order_id, $context );
	}

	/**
	 * Whether the logged-in user is this order's own customer.
	 *
	 * @since 0.6.0
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function current_user_owns( \WC_Order $order ): bool {
		$user_id = get_current_user_id();

		return $user_id > 0 && $user_id === $order->get_customer_id();
	}

	/**
	 * Whether the current request carries a token that verifies against this order.
	 *
	 * The order-key comparison happens here, against the order as it is right
	 * now — not at token-issue time — so rotating the key is a real revocation
	 * path: every token minted against the old key stops verifying immediately.
	 *
	 * @since 0.6.0
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function bearer_token_matches( \WC_Order $order ): bool {
		$token = $this->current_request_token();

		if ( '' === $token ) {
			return false;
		}

		$payload = $this->tokens->decode( $token );

		if ( null === $payload || $payload->order_id !== $order->get_id() ) {
			return false;
		}

		return hash_equals( (string) $order->get_order_key(), $payload->order_key );
	}

	/**
	 * The signed token bound to the current request, if any.
	 *
	 * This class has no opinion on transport: a REST controller carries the
	 * token as a request parameter, the guest landing page exchanges it for a
	 * short-lived cookie-bound context (docs/SPEC.md Phase 8). Whichever
	 * milestone owns that transport supplies it through this filter rather
	 * than this resolver reaching into superglobals for a shape it does not
	 * own yet.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function current_request_token(): string {
		/**
		 * Filters the signed token bound to the current request.
		 *
		 * @since 0.6.0
		 *
		 * @param string $token Empty string when the current request carries none.
		 */
		return (string) apply_filters( 'pph_current_request_token', '' );
	}
}
