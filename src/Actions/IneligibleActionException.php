<?php
/**
 * Eligibility-denial exception.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Thrown by an action's `execute()` when a re-check at execution time fails.
 *
 * Carries the `EligibilityResult` that caused the denial, so a REST
 * controller can map its `reason_code` to the right HTTP status (429 for a
 * cooldown still running, 403 for everything else) and its `message` to the
 * response body, without a second implementation of what each reason means.
 *
 * @since 0.8.0
 */
final class IneligibleActionException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param EligibilityResult $result The denial that caused this exception.
	 */
	public function __construct( public readonly EligibilityResult $result ) {
		// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
		parent::__construct( esc_html( 'Action denied: ' . ( $result->reason_code ?? 'unknown' ) ) );
	}
}
