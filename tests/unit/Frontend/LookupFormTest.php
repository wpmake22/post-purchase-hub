<?php
/**
 * LookupForm unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Frontend\LookupForm;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\LookupResult;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the no-JavaScript half of the lookup surface: the gate, the
 * post/redirect/get cycle, and that a submitted address never reaches rendered
 * markup.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Frontend\LookupForm
 */
final class LookupFormTest extends TestCase {

	/**
	 * Form under test.
	 *
	 * @var LookupForm
	 */
	private LookupForm $form;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		$this->enable();

		$this->form = new LookupForm(
			new GuestAccess(),
			new GuestLookupService(
				new GuestAccess(),
				new OrderLookup(),
				new RateLimiter( new Cache() ),
				new Logger()
			),
			new TemplateLoader( new Logger() )
		);

		add_filter(
			'pph_lookup_time_floor_ms',
			static function (): int {
				return 0;
			}
		);

		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_URI']    = '/track-my-order/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Clears the superglobals this class reads.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();

		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	/**
	 * Turns guest lookup on.
	 *
	 * @return void
	 */
	private function enable(): void {
		FakeWordPress::$options['pph_settings'] = array(
			GuestAccess::ENABLED_SETTING      => true,
			GuestAccess::ACKNOWLEDGED_SETTING => true,
		);
	}

	/**
	 * Puts a submission on the request.
	 *
	 * @param string $number Submitted order number.
	 * @param string $email  Submitted email.
	 * @return void
	 */
	private function submit( string $number, string $email ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.9';
		$_POST                     = array(
			LookupForm::SUBMIT_FIELD => '1',
			LookupForm::NUMBER_FIELD => $number,
			LookupForm::EMAIL_FIELD  => $email,
		);
	}

	/**
	 * A store that has not enabled lookup renders nothing at all — not an
	 * empty wrapper, not a message (CLAUDE.md hard rule 19).
	 *
	 * @return void
	 */
	public function test_it_renders_nothing_when_guest_lookup_is_off(): void {
		FakeWordPress::$options['pph_settings'] = array();

		$this->assertSame( '', $this->form->render() );
	}

	/**
	 * The form renders with both fields and plugin-owned test hooks.
	 *
	 * @return void
	 */
	public function test_it_renders_the_form(): void {
		$markup = $this->form->render();

		$this->assertStringContainsString( 'data-pph-lookup-form', $markup );
		$this->assertStringContainsString( 'data-pph-lookup-number', $markup );
		$this->assertStringContainsString( 'data-pph-lookup-email', $markup );
		$this->assertStringContainsString( 'method="post"', $markup );
		$this->assertStringContainsString( LookupForm::NUMBER_FIELD, $markup );
		$this->assertStringContainsString( LookupForm::EMAIL_FIELD, $markup );
	}

	/**
	 * A submission answers with a redirect rather than rendering, so a refresh
	 * cannot resubmit it.
	 *
	 * @return void
	 */
	public function test_a_submission_redirects_to_the_same_page(): void {
		$this->submit( '42', 'jane@example.com' );

		$target = $this->form->handle_submission();

		$this->assertIsString( $target );
		$this->assertStringContainsString( '/track-my-order/', (string) $target );
		$this->assertStringContainsString( LookupForm::NOTICE_PARAM . '=' . LookupResult::ACCEPTED, (string) $target );
	}

	/**
	 * The redirect target never carries the submitted address, so it is not put
	 * into browser history, a referrer or a server log.
	 *
	 * @return void
	 */
	public function test_the_redirect_target_never_carries_the_submitted_values(): void {
		$this->submit( '42', 'jane@example.com' );

		$target = (string) $this->form->handle_submission();

		$this->assertStringNotContainsString( 'jane', $target );
		$this->assertStringNotContainsString( '42', $target );
	}

	/**
	 * An existing order and one that does not exist redirect to the same place.
	 *
	 * @return void
	 */
	public function test_a_hit_and_a_miss_redirect_identically(): void {
		$order = new \WC_Order( 42, 'processing' );
		$order->set_billing_email( 'jane@example.com' );
		FakeWordPress::$orders[42] = $order;

		$this->submit( '42', 'jane@example.com' );
		$hit = $this->form->handle_submission();

		$this->submit( '999', 'nobody@example.com' );
		$miss = $this->form->handle_submission();

		$this->assertSame( $hit, $miss );
	}

	/**
	 * A throttled submission is told so, generically.
	 *
	 * @return void
	 */
	public function test_a_throttled_submission_redirects_with_the_throttle_notice(): void {
		for ( $attempt = 0; $attempt <= GuestLookupService::IP_LIMIT; $attempt++ ) {
			$this->submit( '999', 'jane@example.com' );
			$target = $this->form->handle_submission();
		}

		$this->assertStringContainsString( LookupForm::NOTICE_PARAM . '=' . LookupResult::THROTTLED, (string) $target );
	}

	/**
	 * A request that is not a submission is left alone.
	 *
	 * @return void
	 */
	public function test_a_plain_page_view_is_not_a_submission(): void {
		$this->assertNull( $this->form->handle_submission() );
	}

	/**
	 * Somebody else's POST to the same page is not ours.
	 *
	 * @return void
	 */
	public function test_another_plugins_post_is_not_a_submission(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'some_other_form' => '1' );

		$this->assertNull( $this->form->handle_submission() );
	}

	/**
	 * A submission on a store that has not enabled lookup is processed by
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_submission_to_a_disabled_store_does_nothing(): void {
		FakeWordPress::$options['pph_settings'] = array();

		$this->submit( '42', 'jane@example.com' );

		$this->assertNull( $this->form->handle_submission() );
		$this->assertSame( array(), FakeWordPress::$logged );
	}

	/**
	 * The notice shown after a redirect comes from a fixed table, not from the
	 * URL — a message echoed out of a query argument is reflected XSS.
	 *
	 * @return void
	 */
	public function test_the_notice_text_comes_from_the_plugin_not_the_url(): void {
		$_GET[ LookupForm::NOTICE_PARAM ] = '<script>alert(1)</script>';

		$markup = $this->form->render();

		$this->assertStringNotContainsString( 'script', $markup );
		$this->assertStringNotContainsString( 'data-pph-lookup-notice', $markup );
	}

	/**
	 * A recognised outcome does render its own message.
	 *
	 * @return void
	 */
	public function test_a_recognised_outcome_renders_its_notice(): void {
		$_GET[ LookupForm::NOTICE_PARAM ] = LookupResult::ACCEPTED;

		$markup = $this->form->render();

		$this->assertStringContainsString( 'data-pph-lookup-notice', $markup );
		$this->assertStringContainsString( esc_html( GuestLookupService::accepted_message() ), $markup );
	}

	/**
	 * The page showing an outcome is marked uncacheable.
	 *
	 * @return void
	 */
	public function test_the_outcome_view_is_marked_uncacheable(): void {
		$_GET[ LookupForm::NOTICE_PARAM ] = LookupResult::ACCEPTED;

		$this->form->render();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * The shortcode and the submission handler are both registered.
	 *
	 * @return void
	 */
	public function test_it_registers_the_shortcode_and_the_handler(): void {
		$this->form->register();

		$this->assertArrayHasKey( LookupForm::TAG, FakeWordPress::$shortcodes );
		$this->assertArrayHasKey( 'template_redirect', FakeWordPress::$actions );
	}
}
