<?php
/**
 * Outcome of one guest-lookup attempt.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * What a caller is allowed to know about an attempt.
 *
 * Note what is absent: whether the order existed, whether the email matched,
 * whether an email was sent. `ACCEPTED` covers every one of those cases and
 * carries the same message for all of them, so an adapter cannot accidentally
 * branch on something that would become an existence oracle in the response
 * (docs/SPEC.md Phase 8). The statuses that *are* distinguishable — throttled,
 * challenged, disabled — describe the request, never the order.
 *
 * @since 0.11.0
 */
final class LookupResult {

	/**
	 * The attempt was processed. Says nothing about what was found.
	 *
	 * @var string
	 */
	public const ACCEPTED = 'accepted';

	/**
	 * A rate limit was already exhausted.
	 *
	 * @var string
	 */
	public const THROTTLED = 'throttled';

	/**
	 * A bot challenge attached by another plugin rejected the attempt.
	 *
	 * @var string
	 */
	public const CHALLENGED = 'challenged';

	/**
	 * Guest lookup is not enabled on this store.
	 *
	 * @var string
	 */
	public const DISABLED = 'disabled';

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param string $status  One of this class's constants.
	 * @param string $message Message for the visitor, already translated.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message
	) {}

	/**
	 * Whether the attempt was processed rather than refused.
	 *
	 * @since 0.11.0
	 *
	 * @return bool
	 */
	public function accepted(): bool {
		return self::ACCEPTED === $this->status;
	}
}
