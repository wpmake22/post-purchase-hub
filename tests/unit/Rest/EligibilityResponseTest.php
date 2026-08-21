<?php
/**
 * EligibilityResponse unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResult;
use PostPurchaseHub\Rest\EligibilityResponse;

/**
 * Covers the one decision this class makes: which denial reasons are a 429
 * versus a 403.
 *
 * @since 0.8.0
 *
 * @covers \PostPurchaseHub\Rest\EligibilityResponse
 */
final class EligibilityResponseTest extends TestCase {

	/**
	 * A cooldown still running is a 429 — the customer may simply retry later.
	 *
	 * @return void
	 */
	public function test_cooldown_is_429(): void {
		$result = EligibilityResult::denied( EligibilityResult::REASON_COOLDOWN_ACTIVE, 'Please wait.' );

		$this->assertSame( 429, EligibilityResponse::status_for( $result ) );
	}

	/**
	 * Every other denial reason is a 403.
	 *
	 * @dataProvider non_cooldown_reasons
	 *
	 * @param string $reason Reason code.
	 * @return void
	 */
	public function test_every_other_reason_is_403( string $reason ): void {
		$result = EligibilityResult::denied( $reason, 'Not eligible.' );

		$this->assertSame( 403, EligibilityResponse::status_for( $result ) );
	}

	/**
	 * The full reason vocabulary except the cooldown.
	 *
	 * @return array<string, array{string}>
	 */
	public static function non_cooldown_reasons(): array {
		return array(
			'order_type_excluded'     => array( EligibilityResult::REASON_ORDER_TYPE_EXCLUDED ),
			'status_not_eligible'     => array( EligibilityResult::REASON_STATUS_NOT_ELIGIBLE ),
			'payment_method_excluded' => array( EligibilityResult::REASON_PAYMENT_METHOD_EXCLUDED ),
			'order_too_new'           => array( EligibilityResult::REASON_ORDER_TOO_NEW ),
			'order_too_old'           => array( EligibilityResult::REASON_ORDER_TOO_OLD ),
			'product_type_excluded'   => array( EligibilityResult::REASON_PRODUCT_TYPE_EXCLUDED ),
			'request_cap_reached'     => array( EligibilityResult::REASON_REQUEST_CAP_REACHED ),
			'a merchant override'     => array( 'merchant_custom_reason' ),
		);
	}
}
