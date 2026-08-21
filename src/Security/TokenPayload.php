<?php
/**
 * Decoded signed-token payload.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * What a token asserts, once its signature and expiry have already checked out.
 *
 * Immutable and storage-free, like every other value object in this codebase:
 * `TokenService::decode()` either returns one of these or `null`, never a
 * half-verified value a caller could be tempted to trust selectively.
 *
 * @since 0.6.0
 */
final class TokenPayload {

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param int    $order_id  Order the token is bound to.
	 * @param string $order_key Order key at the moment the token was issued.
	 * @param int    $expiry    Unix timestamp the token stops verifying at.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $order_key,
		public readonly int $expiry
	) {}
}
