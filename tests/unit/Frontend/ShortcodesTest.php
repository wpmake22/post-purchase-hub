<?php
/**
 * Shortcode unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\Shortcodes;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers what the shortcode will and will not accept.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\Shortcodes
 */
final class ShortcodesTest extends TestCase {

	/**
	 * Shortcodes under test.
	 *
	 * @var Shortcodes
	 */
	private Shortcodes $shortcodes;

	/**
	 * Builds the service over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$stages = new StageMap( new StatusDetector( new Cache() ) );

		$this->shortcodes = new Shortcodes(
			new Renderer(
				new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) ),
				new TemplateLoader( new Logger() ),
				new EstimatedDelivery( new NullTrackingAvailability(), new Logger() )
			)
		);
	}

	/**
	 * A signed-out visitor is asked to sign in and shown no orders.
	 *
	 * @return void
	 */
	public function test_a_guest_is_shown_no_orders(): void {
		FakeWordPress::$current_user_id = 0;
		FakeWordPress::$orders          = array( 1 => new \WC_Order( 1, 'processing' ) );

		$output = $this->shortcodes->render( array() );

		$this->assertStringContainsString( 'data-pph-orders-empty', $output );
		$this->assertStringNotContainsString( 'data-pph-timeline', $output );
	}

	/**
	 * The shortcode accepts no attribute naming an order.
	 *
	 * Order ids are sequential and guessable, so an embed that could name one
	 * would be an enumeration tool on any page a merchant published.
	 *
	 * @dataProvider forged_attributes
	 *
	 * @param array<string, string> $atts Attributes an attacker might try.
	 * @return void
	 */
	public function test_no_attribute_can_name_an_order( array $atts ): void {
		FakeWordPress::$current_user_id = 7;
		FakeWordPress::$orders          = array();

		$output = $this->shortcodes->render( $atts );

		$this->assertStringNotContainsString( 'data-pph-order-id="99"', $output );
	}

	/**
	 * Attributes that must have no effect.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function forged_attributes(): array {
		return array(
			'order id' => array( array( 'order_id' => '99' ) ),
			'order'    => array( array( 'order' => '99' ) ),
			'customer' => array( array( 'customer' => '99' ) ),
			'id'       => array( array( 'id' => '99' ) ),
		);
	}

	/**
	 * The limit is clamped, so an embed cannot ask for the whole store.
	 *
	 * @dataProvider limits
	 *
	 * @param mixed $requested Requested limit.
	 * @param int   $expected  Limit that should reach the query.
	 * @return void
	 */
	public function test_the_limit_is_clamped( $requested, int $expected ): void {
		FakeWordPress::$current_user_id = 7;

		for ( $i = 1; $i <= 60; $i++ ) {
			FakeWordPress::$orders[ $i ] = new \WC_Order( $i, 'processing' );
		}

		$output = $this->shortcodes->render( array( 'limit' => $requested ) );

		$this->assertSame( $expected, substr_count( $output, 'class="pph-orders__order"' ) );
	}

	/**
	 * Limits a page might supply, and what each should produce.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function limits(): array {
		return array(
			'default'      => array( Shortcodes::DEFAULT_LIMIT, 10 ),
			'small'        => array( '3', 3 ),
			'zero'         => array( '0', Shortcodes::DEFAULT_LIMIT ),
			'negative'     => array( '-5', Shortcodes::DEFAULT_LIMIT ),
			'over the cap' => array( '500', Shortcodes::MAX_LIMIT ),
			'nonsense'     => array( 'all', Shortcodes::DEFAULT_LIMIT ),
		);
	}
}
