<?php
/**
 * Help-request email unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\HelpContext;
use PostPurchaseHub\Emails\HelpRequest;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M13's escaping criterion, asserted against the rendered templates rather
 * than the class: "help submission arrives with full order context and no
 * unescaped input in either email format". Every field a customer can
 * influence is loaded with a payload and both formats are checked.
 *
 * @since 0.13.0
 *
 * @covers \PostPurchaseHub\Emails\HelpRequest
 */
final class HelpRequestTest extends TestCase {

	/**
	 * The payload every customer-influenced field carries.
	 *
	 * @var string
	 */
	private const PAYLOAD = '<script>alert(1)</script>';

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		FakeWordPress::$options['admin_email'] = 'owner@example.test';
	}

	/**
	 * A submission whose every free-ish field carries the payload.
	 *
	 * The message is the field a customer actually writes; the name and email
	 * come off the order, which an attacker who placed it also wrote. The topic
	 * label is a filterable string, so it is covered too.
	 *
	 * @return HelpContext
	 */
	private function context(): HelpContext {
		return new HelpContext(
			8500,
			'8500' . self::PAYLOAD,
			'processing',
			'Processing' . self::PAYLOAD,
			'21 August 2026',
			'Being packed' . self::PAYLOAD,
			array( '2 × Blue shirt' . self::PAYLOAD ),
			3,
			'billing',
			'A billing question' . self::PAYLOAD,
			'Please call me ' . self::PAYLOAD,
			'Ada ' . self::PAYLOAD,
			'customer@example.test',
			'account',
			'https://shop.test/wp-admin/post.php?post=8500&action=edit'
		);
	}

	/**
	 * Both formats, rendered.
	 *
	 * @return array{html: string, plain: string}
	 */
	private function rendered(): array {
		$email = new HelpRequest();

		$email->trigger( $this->context(), new \WC_Order( 8500, 'processing' ) );

		return array(
			'html'  => $email->get_content_html(),
			'plain' => $email->get_content_plain(),
		);
	}

	/**
	 * Neither format lets a payload through as markup, and both still show the
	 * text itself — escaped, not stripped, so the merchant reads what the
	 * customer wrote.
	 *
	 * @return void
	 */
	public function test_neither_format_leaks_an_unescaped_payload(): void {
		$rendered = $this->rendered();

		foreach ( $rendered as $format => $content ) {
			$this->assertNotSame( '', $content, 'The ' . $format . ' template rendered nothing.' );
			$this->assertStringNotContainsString( '<script>', $content, $format . ' must not contain a raw <script> tag.' );
			$this->assertStringNotContainsString( '</script>', $content, $format . ' must not contain a raw closing script tag.' );
			$this->assertStringContainsString( 'alert(1)', $content, $format . ' still shows the text, escaped.' );
		}
	}

	/**
	 * Both formats carry the context the merchant needs to answer without
	 * asking anything back.
	 *
	 * @return void
	 */
	public function test_both_formats_carry_the_order_context(): void {
		$rendered = $this->rendered();

		foreach ( $rendered as $format => $content ) {
			$this->assertStringContainsString( '8500', $content, $format . ' names the order.' );
			$this->assertStringContainsString( 'Processing', $content, $format . ' states the status.' );
			$this->assertStringContainsString( 'Being packed', $content, $format . ' states the timeline state.' );
			$this->assertStringContainsString( 'Blue shirt', $content, $format . ' lists the items.' );
			$this->assertStringContainsString( 'Please call me', $content, $format . ' carries the message.' );
			$this->assertStringContainsString( 'customer@example.test', $content, $format . ' says where to reply.' );
			$this->assertStringContainsString( 'and 3 more items', $content, $format . ' accounts for the items past the cap.' );
		}
	}

	/**
	 * The email defaults to the site admin, and the subject names the order.
	 *
	 * @return void
	 */
	public function test_it_defaults_to_the_site_admin(): void {
		$email = new HelpRequest();

		$email->trigger( $this->context(), new \WC_Order( 8500, 'processing' ) );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'owner@example.test', FakeWordPress::$sent_emails[0]['to'] );
		$this->assertStringContainsString( '8500', FakeWordPress::$sent_emails[0]['subject'] );
	}

	/**
	 * A merchant's configured recipient is used instead.
	 *
	 * @return void
	 */
	public function test_the_recipient_is_configurable(): void {
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'recipient' => 'support@example.test' );

		$email = new HelpRequest();

		$email->trigger( $this->context(), new \WC_Order( 8500, 'processing' ) );

		$this->assertCount( 1, FakeWordPress::$sent_emails );
		$this->assertSame( 'support@example.test', FakeWordPress::$sent_emails[0]['to'] );
	}

	/**
	 * Switching the email off stops the send, and `will_send()` says so before
	 * a form is ever drawn.
	 *
	 * @return void
	 */
	public function test_a_disabled_email_sends_nothing(): void {
		FakeWordPress::$options[ HelpRequest::SETTINGS_OPTION ] = array( 'enabled' => 'no' );

		$this->assertFalse( HelpRequest::will_send() );

		$email = new HelpRequest();

		$email->trigger( $this->context(), new \WC_Order( 8500, 'processing' ) );

		$this->assertSame( array(), FakeWordPress::$sent_emails );
	}

	/**
	 * An unconfigured store has the email on, matching the field's default.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_store_has_it_enabled(): void {
		$this->assertTrue( HelpRequest::will_send() );
	}

	/**
	 * The email registers itself against the hand-off action, so a submission
	 * needs nothing else wired to reach the store.
	 *
	 * @return void
	 */
	public function test_it_listens_to_the_handoff_action(): void {
		new HelpRequest();

		$this->assertArrayHasKey( 'pph_help_submitted', FakeWordPress::$actions );
	}
}
