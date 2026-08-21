<?php
/**
 * Outcome of an eligibility check.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Whether an action applies to an order, and why.
 *
 * `reason_code` is a stable, machine-readable string — logged, and usable for
 * support diagnosis — never translated and never shown to a customer
 * directly. `message` is the customer-facing sentence; a caller building a
 * REST response or a rendered denial notice reads that instead.
 *
 * @since 0.7.0
 */
final class EligibilityResult {

	const REASON_ORDER_TYPE_EXCLUDED     = 'order_type_excluded';
	const REASON_STATUS_NOT_ELIGIBLE     = 'status_not_eligible';
	const REASON_PAYMENT_METHOD_EXCLUDED = 'payment_method_excluded';
	const REASON_ORDER_TOO_NEW           = 'order_too_new';
	const REASON_ORDER_TOO_OLD           = 'order_too_old';
	const REASON_PRODUCT_TYPE_EXCLUDED   = 'product_type_excluded';
	const REASON_REQUEST_CAP_REACHED     = 'request_cap_reached';
	const REASON_COOLDOWN_ACTIVE         = 'cooldown_active';

	/**
	 * Constructor. Private: build through allowed() or denied().
	 *
	 * @since 0.7.0
	 *
	 * @param bool        $eligible    Whether the action applies.
	 * @param string|null $reason_code Stable machine reason, null when eligible.
	 * @param string      $message     Customer-facing sentence, empty when eligible.
	 */
	private function __construct(
		public readonly bool $eligible,
		public readonly ?string $reason_code,
		public readonly string $message
	) {}

	/**
	 * Builds an "eligible" result.
	 *
	 * @since 0.7.0
	 * @return self
	 */
	public static function allowed(): self {
		return new self( true, null, '' );
	}

	/**
	 * Builds an "ineligible" result.
	 *
	 * @since 0.7.0
	 *
	 * @param string $reason_code Stable machine reason, one of the REASON_* constants or a caller's own.
	 * @param string $message     Customer-facing sentence.
	 * @return self
	 */
	public static function denied( string $reason_code, string $message ): self {
		return new self( false, $reason_code, $message );
	}
}
