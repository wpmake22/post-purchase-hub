<?php
/**
 * Maps an eligibility denial to an HTTP status.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Actions\EligibilityResult;

/**
 * One place that decides which HTTP status an ineligibility reason gets.
 *
 * A cooldown still running is a 429 — the customer may simply try again once
 * it elapses — every other reason is a 403: the order itself is not, and will
 * not become, eligible for this action right now.
 *
 * @since 0.8.0
 */
final class EligibilityResponse {

	/**
	 * The HTTP status for a denied EligibilityResult.
	 *
	 * @since 0.8.0
	 *
	 * @param EligibilityResult $result A result with `eligible === false`.
	 * @return int
	 */
	public static function status_for( EligibilityResult $result ): int {
		return EligibilityResult::REASON_COOLDOWN_ACTIVE === $result->reason_code ? 429 : 403;
	}
}
