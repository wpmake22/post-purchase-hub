<?php
/**
 * EligibilityResolver unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\EligibilityResult;
use PostPurchaseHub\Actions\EligibilityRule;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the eligibility matrix docs/SPEC.md and MILESTONE-PROMPTS.md M07
 * both name: order status x payment method x order type x age, generated
 * rather than hand-written, plus product type, cap and cooldown as dedicated
 * tests. Every case here calls resolve() directly — the "action executor" —
 * never through a rendered button, so a case failing here is proof that
 * hiding a button was never the only enforcement.
 *
 * @since 0.7.0
 *
 * @covers \PostPurchaseHub\Actions\EligibilityResolver
 */
final class EligibilityResolverTest extends TestCase {

	/**
	 * Allowed statuses for the matrix rule: a representative "cancel"-shaped
	 * rule exercising every dimension the matrix varies.
	 *
	 * @var string[]
	 */
	private const ALLOWED_STATUSES = array( 'pending', 'processing', 'on-hold' );

	/**
	 * Excluded payment method for the matrix rule.
	 *
	 * @var string
	 */
	private const EXCLUDED_PAYMENT_METHOD = 'cod';

	/**
	 * Excluded order type for the matrix rule.
	 *
	 * @var string
	 */
	private const EXCLUDED_ORDER_TYPE = 'shop_subscription';

	/**
	 * Age ceiling, in days, for the matrix rule.
	 *
	 * @var int
	 */
	private const MAX_AGE_DAYS = 30;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Every generated status x payment method x order type x age combination
	 * matches an independently computed expectation.
	 *
	 * @dataProvider matrix
	 *
	 * @param string      $status         Unprefixed order status.
	 * @param string      $payment_method Payment gateway id.
	 * @param string      $order_type     Value of get_type().
	 * @param int         $age_days       Order age in days.
	 * @param bool        $expect_eligible Independently computed expectation.
	 * @param string|null $expect_reason   Expected reason code when ineligible.
	 * @return void
	 */
	public function test_matrix( string $status, string $payment_method, string $order_type, int $age_days, bool $expect_eligible, ?string $expect_reason ): void {
		$order = $this->order( $status, $payment_method, $order_type, $age_days );
		$rule  = $this->matrix_rule();

		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', $order, $rule );

		$this->assertSame( $expect_eligible, $result->eligible );

		if ( ! $expect_eligible ) {
			$this->assertSame( $expect_reason, $result->reason_code );
		}
	}

	/**
	 * Generates the matrix and its independently computed expectations.
	 *
	 * The independent expectation below is written from the rule's stated boundaries, never by
	 * calling EligibilityResolver — a swapped priority or an off-by-one in the
	 * production evaluation order will disagree with it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: bool, 5: string|null}>
	 */
	public static function matrix(): array {
		$statuses        = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled' );
		$payment_methods = array( 'cod', 'bacs', 'stripe' );
		$order_types     = array( 'shop_order', 'shop_subscription' );
		$ages_days       = array( 0, 10, 40 );

		$cases = array();

		foreach ( $statuses as $status ) {
			foreach ( $payment_methods as $payment_method ) {
				foreach ( $order_types as $order_type ) {
					foreach ( $ages_days as $age_days ) {
						[$eligible, $reason] = self::expected_outcome( $status, $payment_method, $order_type, $age_days );

						$cases[ "$status|$payment_method|$order_type|{$age_days}d" ] = array(
							$status,
							$payment_method,
							$order_type,
							$age_days,
							$eligible,
							$reason,
						);
					}
				}
			}
		}

		return $cases;
	}

	/**
	 * Independently computes the expected outcome for one matrix cell.
	 *
	 * @param string $status         Unprefixed order status.
	 * @param string $payment_method Payment gateway id.
	 * @param string $order_type     Value of get_type().
	 * @param int    $age_days       Order age in days.
	 * @return array{0: bool, 1: string|null}
	 */
	private static function expected_outcome( string $status, string $payment_method, string $order_type, int $age_days ): array {
		if ( self::EXCLUDED_ORDER_TYPE === $order_type ) {
			return array( false, EligibilityResult::REASON_ORDER_TYPE_EXCLUDED );
		}

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return array( false, EligibilityResult::REASON_STATUS_NOT_ELIGIBLE );
		}

		if ( self::EXCLUDED_PAYMENT_METHOD === $payment_method ) {
			return array( false, EligibilityResult::REASON_PAYMENT_METHOD_EXCLUDED );
		}

		if ( $age_days > self::MAX_AGE_DAYS ) {
			return array( false, EligibilityResult::REASON_ORDER_TOO_OLD );
		}

		return array( true, null );
	}

	/**
	 * The exact age boundary is respected: within the ceiling is eligible, one
	 * second past it is not.
	 *
	 * @return void
	 */
	public function test_age_boundary_is_exact(): void {
		$rule     = new EligibilityRule( max_age_seconds: DAY_IN_SECONDS );
		$resolver = new EligibilityResolver( new FakeRequestHistory() );

		$within = $this->order_created_seconds_ago( DAY_IN_SECONDS - 1 );
		$this->assertTrue( $resolver->resolve( 'cancel', $within, $rule )->eligible );

		$exactly = $this->order_created_seconds_ago( DAY_IN_SECONDS );
		$this->assertTrue( $resolver->resolve( 'cancel', $exactly, $rule )->eligible );

		$past   = $this->order_created_seconds_ago( DAY_IN_SECONDS + 1 );
		$result = $resolver->resolve( 'cancel', $past, $rule );
		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_ORDER_TOO_OLD, $result->reason_code );
	}

	/**
	 * A minimum age denies an order that has not reached it yet.
	 *
	 * @return void
	 */
	public function test_minimum_age_denies_too_new_an_order(): void {
		$rule     = new EligibilityRule( min_age_seconds: DAY_IN_SECONDS );
		$resolver = new EligibilityResolver( new FakeRequestHistory() );

		$result = $resolver->resolve( 'cancel', $this->order_created_seconds_ago( 10 ), $rule );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_ORDER_TOO_NEW, $result->reason_code );
	}

	/**
	 * An order with no recorded creation date passes the age check rather
	 * than being denied over a data anomaly it did not cause.
	 *
	 * @return void
	 */
	public function test_missing_creation_date_does_not_fail_the_age_check(): void {
		$order = new \WC_Order( 1, 'pending' );
		$rule  = new EligibilityRule( max_age_seconds: DAY_IN_SECONDS );

		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', $order, $rule );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * A line item whose product is an excluded type denies the whole order.
	 *
	 * @return void
	 */
	public function test_excluded_product_type_denies_the_order(): void {
		$order = new \WC_Order( 1, 'pending' );
		$order->set_items(
			array(
				new \WC_Order_Item_Product( new \WC_Product( 'simple' ) ),
				new \WC_Order_Item_Product( new \WC_Product( 'booking' ) ),
			)
		);

		$rule   = new EligibilityRule( excluded_product_types: array( 'booking' ) );
		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', $order, $rule );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_PRODUCT_TYPE_EXCLUDED, $result->reason_code );
	}

	/**
	 * An order whose products are all allowed types is unaffected by the
	 * product-type exclusion.
	 *
	 * @return void
	 */
	public function test_no_excluded_product_is_unaffected(): void {
		$order = new \WC_Order( 1, 'pending' );
		$order->set_items( array( new \WC_Order_Item_Product( new \WC_Product( 'simple' ) ) ) );

		$rule   = new EligibilityRule( excluded_product_types: array( 'booking' ) );
		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', $order, $rule );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * A line item that has lost its product (deleted) is skipped, not treated
	 * as excluded or as a fatal.
	 *
	 * @return void
	 */
	public function test_a_line_item_with_no_product_is_skipped(): void {
		$order = new \WC_Order( 1, 'pending' );
		$order->set_items( array( new \WC_Order_Item_Product( null ) ) );

		$rule   = new EligibilityRule( excluded_product_types: array( 'booking' ) );
		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', $order, $rule );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * An order at its request cap is denied; one below it is not.
	 *
	 * @return void
	 */
	public function test_per_order_cap_is_enforced(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 100 ) );

		$rule = new EligibilityRule( per_order_cap: 2 );

		$below_cap = ( new EligibilityResolver( $history ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );
		$this->assertTrue( $below_cap->eligible );

		$history->add( 1, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 50 ) );

		$at_cap = ( new EligibilityResolver( $history ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );
		$this->assertFalse( $at_cap->eligible );
		$this->assertSame( EligibilityResult::REASON_REQUEST_CAP_REACHED, $at_cap->reason_code );
	}

	/**
	 * The cap is scoped per order: another order's requests do not count against it.
	 *
	 * @return void
	 */
	public function test_per_order_cap_does_not_leak_across_orders(): void {
		$history = new FakeRequestHistory();
		$history->add( 999, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 100 ) );
		$history->add( 999, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 50 ) );

		$rule   = new EligibilityRule( per_order_cap: 2 );
		$result = ( new EligibilityResolver( $history ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * A request made inside the cooldown window denies a second one; one made
	 * outside it does not.
	 *
	 * @return void
	 */
	public function test_cooldown_is_enforced(): void {
		$rule = new EligibilityRule( cooldown_seconds: HOUR_IN_SECONDS );

		$recent = new FakeRequestHistory();
		$recent->add( 1, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$still_cooling = ( new EligibilityResolver( $recent ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );
		$this->assertFalse( $still_cooling->eligible );
		$this->assertSame( EligibilityResult::REASON_COOLDOWN_ACTIVE, $still_cooling->reason_code );

		$old = new FakeRequestHistory();
		$old->add( 1, 'cancel', gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) );
		$elapsed = ( new EligibilityResolver( $old ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );
		$this->assertTrue( $elapsed->eligible );
	}

	/**
	 * When a rule names an explicit history_type, the cap and cooldown checks
	 * query by that — not by the action id resolve() was called with. An
	 * action's registry id and its stored request type are not guaranteed to
	 * be the same string (Actions\Cancel is exactly this case: id `cancel`,
	 * type `cancellation`), and a resolver that queried by action id here
	 * would silently never find any prior request at all.
	 *
	 * @return void
	 */
	public function test_history_type_overrides_the_action_id_for_cooldown(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, 'a_different_stored_type', gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$rule = new EligibilityRule( cooldown_seconds: HOUR_IN_SECONDS, history_type: 'a_different_stored_type' );

		// Resolved under an action id that does not match the stored type at
		// all — only history_type should matter.
		$result = ( new EligibilityResolver( $history ) )->resolve( 'an_unrelated_action_id', new \WC_Order( 1, 'pending' ), $rule );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_COOLDOWN_ACTIVE, $result->reason_code );
	}

	/**
	 * The same override applies to the per-order cap.
	 *
	 * @return void
	 */
	public function test_history_type_overrides_the_action_id_for_the_cap(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, 'a_different_stored_type', gmdate( 'Y-m-d H:i:s', time() - 100 ) );

		$rule = new EligibilityRule( per_order_cap: 1, history_type: 'a_different_stored_type' );

		$result = ( new EligibilityResolver( $history ) )->resolve( 'an_unrelated_action_id', new \WC_Order( 1, 'pending' ), $rule );

		$this->assertFalse( $result->eligible );
		$this->assertSame( EligibilityResult::REASON_REQUEST_CAP_REACHED, $result->reason_code );
	}

	/**
	 * With no history_type set, the action id is the fallback — the behaviour
	 * every case above this one already relies on.
	 *
	 * @return void
	 */
	public function test_history_type_defaults_to_the_action_id(): void {
		$history = new FakeRequestHistory();
		$history->add( 1, 'cancel', gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$rule   = new EligibilityRule( cooldown_seconds: HOUR_IN_SECONDS );
		$result = ( new EligibilityResolver( $history ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );

		$this->assertFalse( $result->eligible );
	}

	/**
	 * An order with no prior request of this type is never denied by the
	 * cooldown check.
	 *
	 * @return void
	 */
	public function test_cooldown_does_not_apply_with_no_prior_request(): void {
		$rule   = new EligibilityRule( cooldown_seconds: HOUR_IN_SECONDS );
		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), $rule );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * The wpmphub_action_eligibility filter can override a computed result.
	 *
	 * @return void
	 */
	public function test_the_eligibility_filter_can_override_the_result(): void {
		add_filter(
			'wpmphub_action_eligibility',
			static function ( EligibilityResult $result, string $action_id ): EligibilityResult {
				return 'cancel' === $action_id ? EligibilityResult::denied( 'merchant_override', 'Not right now.' ) : $result;
			}
		);

		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), new EligibilityRule() );

		$this->assertFalse( $result->eligible );
		$this->assertSame( 'merchant_override', $result->reason_code );
	}

	/**
	 * A filter returning something other than an EligibilityResult is ignored
	 * rather than corrupting the outcome.
	 *
	 * @return void
	 */
	public function test_a_malformed_filter_return_is_ignored(): void {
		add_filter(
			'wpmphub_action_eligibility',
			static function () {
				return 'not-a-result';
			}
		);

		$result = ( new EligibilityResolver( new FakeRequestHistory() ) )->resolve( 'cancel', new \WC_Order( 1, 'pending' ), new EligibilityRule() );

		$this->assertTrue( $result->eligible );
	}

	/**
	 * Builds an order for one matrix cell.
	 *
	 * @param string $status         Unprefixed order status.
	 * @param string $payment_method Payment gateway id.
	 * @param string $order_type     Value of get_type().
	 * @param int    $age_days       Order age in days.
	 * @return \WC_Order
	 */
	private function order( string $status, string $payment_method, string $order_type, int $age_days ): \WC_Order {
		$order = new \WC_Order( 1, $status );
		$order->set_payment_method( $payment_method );
		$order->set_type( $order_type );
		$order->set_date( 'created', new \WC_DateTime( '@' . ( time() - $age_days * DAY_IN_SECONDS ) ) );

		return $order;
	}

	/**
	 * The rule the generated matrix evaluates every case against.
	 *
	 * @return EligibilityRule
	 */
	private function matrix_rule(): EligibilityRule {
		return new EligibilityRule(
			allowed_statuses: self::ALLOWED_STATUSES,
			max_age_seconds: self::MAX_AGE_DAYS * DAY_IN_SECONDS,
			excluded_payment_methods: array( self::EXCLUDED_PAYMENT_METHOD ),
			excluded_order_types: array( self::EXCLUDED_ORDER_TYPE )
		);
	}

	/**
	 * Builds an order created a given number of seconds ago.
	 *
	 * @param int $seconds Seconds ago the order was created.
	 * @return \WC_Order
	 */
	private function order_created_seconds_ago( int $seconds ): \WC_Order {
		$order = new \WC_Order( 1, 'pending' );
		$order->set_date( 'created', new \WC_DateTime( '@' . ( time() - $seconds ) ) );

		return $order;
	}
}
