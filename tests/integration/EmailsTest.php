<?php
/**
 * Emails integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Emails\AdminDigest;
use PostPurchaseHub\Emails\HelpRequest;
use PostPurchaseHub\Emails\LinkInjector;
use PostPurchaseHub\Emails\Mailer;
use PostPurchaseHub\Emails\NewRequestAdmin;
use PostPurchaseHub\Emails\RequestApproved;
use PostPurchaseHub\Emails\RequestDeclined;
use PostPurchaseHub\Emails\RequestReceived;
use PostPurchaseHub\Emails\SecureLink;
use PostPurchaseHub\Emails\SecureOrderLink;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

/**
 * Exercises the milestone's real dependencies: the actual `WC_Email` base
 * class, `wc_get_template_html()` rendering the real `templates/emails/`
 * files, and WordPress's own locale switcher.
 *
 * Not executed in the session that wrote it — this environment has no
 * `WP_TESTS_DIR` / wp-env WordPress test library available, so `composer
 * test:int` could not be run here. Written to the same conventions as the
 * rest of this suite (`AdminRequestResolutionTest`) so it runs the moment
 * wp-env is available; see the milestone report's Tests section.
 *
 * @since 0.10.0
 *
 * @covers \PostPurchaseHub\Emails\RequestReceived
 * @covers \PostPurchaseHub\Emails\RequestApproved
 * @covers \PostPurchaseHub\Emails\RequestDeclined
 * @covers \PostPurchaseHub\Emails\NewRequestAdmin
 * @covers \PostPurchaseHub\Emails\HelpRequest
 * @covers \PostPurchaseHub\Emails\SecureOrderLink
 * @covers \PostPurchaseHub\Emails\AdminDigest
 * @covers \PostPurchaseHub\Emails\LinkInjector
 * @covers \PostPurchaseHub\Emails\LocaleResolver
 */
final class EmailsTest extends \WP_UnitTestCase {

	/**
	 * Creates the tables once, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		Schema::install();
	}

	/**
	 * A real order with a billing email, ready for a request to be raised
	 * against it.
	 *
	 * @param string $billing_email Billing email address.
	 * @return \WC_Order
	 */
	private function order( string $billing_email = 'customer@example.test' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_billing_email( $billing_email );
		$order->set_billing_first_name( 'Jamie' );
		$order->save();

		return $order;
	}

	/**
	 * A pending cancellation request against a given order.
	 *
	 * @param int    $order_id      Order id.
	 * @param string $customer_note Customer-supplied note.
	 * @return Request
	 */
	private function request( int $order_id, string $customer_note = '' ): Request {
		$repository = new RequestRepository();

		$data = array(
			'order_id'            => $order_id,
			'customer_email_hash' => str_repeat( 'a', 64 ),
			'type'                => Request::TYPE_CANCELLATION,
			'reason_code'         => 'changed_mind',
			'source'              => Request::SOURCE_ACCOUNT,
		);

		if ( '' !== $customer_note ) {
			$data['customer_note'] = $customer_note;
		}

		return $repository->find( $repository->create( $data ) );
	}

	/**
	 * The mailer under test, with its real dependencies.
	 *
	 * @return Mailer
	 */
	private function mailer(): Mailer {
		return new Mailer( new RequestRepository(), new TokenService() );
	}

	/**
	 * Request received / approved / declined and the admin new-request email
	 * all resolve the real recipient and substitute the order number into the
	 * real subject line.
	 *
	 * @return void
	 */
	public function test_request_lifecycle_emails_resolve_recipient_and_subject(): void {
		$order   = $this->order( 'customer@example.test' );
		$request = $this->request( $order->get_id() );

		$received = new RequestReceived();
		$received->trigger( $request, $order );
		$this->assertSame( 'customer@example.test', $received->get_recipient() );
		$this->assertStringContainsString( (string) $order->get_order_number(), $received->get_subject() );

		$approved = new RequestApproved();
		$approved->trigger( $request, $order );
		$this->assertSame( 'customer@example.test', $approved->get_recipient() );

		$declined = new RequestDeclined();
		$declined->trigger( $request, $order );
		$this->assertSame( 'customer@example.test', $declined->get_recipient() );

		$admin = new NewRequestAdmin();
		$admin->trigger( $request, $order );
		$this->assertSame( get_option( 'admin_email' ), $admin->get_recipient() );
	}

	/**
	 * Every request-lifecycle template renders without a fatal, in both
	 * formats, and neither format leaks an unescaped XSS payload placed in
	 * the customer note.
	 *
	 * @return void
	 */
	public function test_templates_render_and_escape_an_xss_payload_in_the_note(): void {
		$payload = '<script>alert(1)</script>';
		$order   = $this->order();
		$request = $this->request( $order->get_id(), $payload );

		foreach ( array( new RequestReceived(), new NewRequestAdmin() ) as $email ) {
			$email->trigger( $request, $order );

			$html  = $email->get_content_html();
			$plain = $email->get_content_plain();

			$this->assertStringNotContainsString( '<script>', $html, get_class( $email ) . ' HTML must not contain a raw <script> tag.' );
			$this->assertStringNotContainsString( '<script>', $plain, get_class( $email ) . ' plain text must not contain a raw <script> tag.' );
			$this->assertStringContainsString( 'alert(1)', $html, 'The note text itself still renders, only escaped.' );
		}
	}

	/**
	 * The declined email never renders the internal admin_note, in either
	 * format.
	 *
	 * @return void
	 */
	public function test_declined_email_never_renders_the_admin_note(): void {
		$order   = $this->order();
		$request = $this->request( $order->get_id() );

		( new RequestRepository() )->update(
			$request->id,
			array(
				'status'      => Request::STATUS_DECLINED,
				'admin_note'  => 'INTERNAL_ONLY_MARKER',
				'resolved_by' => 1,
			)
		);
		$resolved = ( new RequestRepository() )->find( $request->id );

		$email = new RequestDeclined();
		$email->trigger( $resolved, $order );

		$this->assertStringNotContainsString( 'INTERNAL_ONLY_MARKER', $email->get_content_html() );
		$this->assertStringNotContainsString( 'INTERNAL_ONLY_MARKER', $email->get_content_plain() );
	}

	/**
	 * The secure-link email's URL decodes to the right order via the real
	 * TokenService, and the link is absent the moment the order key rotates.
	 *
	 * @return void
	 */
	public function test_secure_link_resolves_to_the_correct_order(): void {
		$order  = $this->order();
		$tokens = new TokenService();

		$url = SecureLink::url( $order, $tokens );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$payload = $tokens->decode( (string) $args[ SecureLink::TOKEN_PARAM ] );

		$this->assertNotNull( $payload );
		$this->assertSame( $order->get_id(), $payload->order_id );
		$this->assertSame( $order->get_order_key(), $payload->order_key );

		$email = new SecureOrderLink( $tokens );
		$email->trigger( $order );
		$this->assertStringContainsString( 'view-order', $email->get_content_html() );
	}

	/**
	 * A locale recorded on the order by WPML/WooCommerce Multilingual (the
	 * `wpml_language` meta key) is what a customer email actually switches
	 * to — not the site's own locale WC_Email::setup_locale() would otherwise
	 * pick.
	 *
	 * @return void
	 */
	public function test_customer_email_switches_to_the_orders_wpml_language(): void {
		if ( ! in_array( 'fr_FR', get_available_languages(), true ) ) {
			$this->markTestSkipped( 'fr_FR is not installed in this test environment.' );
		}

		$order = $this->order();
		$order->update_meta_data( 'wpml_language', 'fr_FR' );
		$order->save();

		$request = $this->request( $order->get_id() );

		$email = new RequestReceived();
		$email->trigger( $request, $order );

		$this->assertSame( 'fr_FR', get_locale(), 'setup_locale() must have switched to the order language before send_notification() built the content.' );
	}

	/**
	 * The admin digest is disabled by default, advances its own marker, and
	 * keeps reporting for as long as anything is still pending.
	 *
	 * The contract worth being explicit about is the last assertion pair. This
	 * is a digest *of pending requests* — its own template prints "N new since
	 * your last digest" and "N currently pending" as two separate lines, which
	 * only makes sense if a digest can go out with nothing new and something
	 * still outstanding. A merchant with five requests sitting unactioned for a
	 * week should keep hearing about them; silence once the marker advanced
	 * would be the digest quietly giving up. What it must never do is send when
	 * there is genuinely nothing to say, which is the final case here.
	 *
	 * @return void
	 */
	public function test_admin_digest_counts_and_advances_its_own_marker(): void {
		delete_option( AdminDigest::LAST_SENT_OPTION );

		$digest = $this->mailer()->admin_digest();
		$this->assertFalse( $digest->maybe_send(), 'Disabled by default.' );

		update_option( 'woocommerce_pph_admin_digest_settings', array( 'enabled' => 'yes' ) );

		// Enabled, but there is nothing to report yet.
		$this->assertFalse( $this->mailer()->admin_digest()->maybe_send(), 'Opting in never means a daily email that says zero.' );

		$order   = $this->order();
		$request = $this->request( $order->get_id() );

		$this->assertTrue( $this->mailer()->admin_digest()->maybe_send() );
		$this->assertNotSame( '', get_option( AdminDigest::LAST_SENT_OPTION, '' ) );

		// Nothing new, but the request is still pending: still worth a nudge.
		$this->assertTrue( $this->mailer()->admin_digest()->maybe_send(), 'A still-pending queue is still something to report.' );

		// Resolved: nothing new and nothing pending, so nothing to send.
		( new RequestRepository() )->update( $request->id, array( 'status' => Request::STATUS_APPROVED ) );

		$this->assertFalse( $this->mailer()->admin_digest()->maybe_send(), 'An empty queue with no new activity sends nothing.' );
	}

	/**
	 * The opt-in link injector renders into a target email once a merchant
	 * enables it on that email's own settings screen, and not before.
	 *
	 * @return void
	 */
	public function test_link_injector_renders_only_once_opted_in(): void {
		$order    = $this->order();
		$injector = new LinkInjector( new TokenService() );

		$captured = '';
		ob_start();
		$injector->maybe_inject( $order, false, false, new \WC_Email_Customer_Processing_Order() );
		$captured = (string) ob_get_clean();
		$this->assertSame( '', $captured, 'Off by default.' );

		update_option( 'woocommerce_customer_processing_order_settings', array( LinkInjector::SETTINGS_FIELD => 'yes' ) );

		ob_start();
		$injector->maybe_inject( $order, false, false, new \WC_Email_Customer_Processing_Order() );
		$captured = (string) ob_get_clean();
		$this->assertStringContainsString( 'view-order', $captured );
	}

	/**
	 * The help email renders both real templates against a real order, with the
	 * order context attached and a payload in the customer's own words escaped
	 * in both formats — M13's acceptance criterion, against real WooCommerce
	 * rendering rather than the unit suite's shims.
	 *
	 * @return void
	 */
	public function test_help_email_renders_and_escapes_the_customers_message(): void {
		$payload = '<script>alert(1)</script>';
		$order   = $this->order();
		$order->set_status( 'processing' );
		$order->save();

		$stages   = new StageMap( new StatusDetector( new Cache() ) );
		$timeline = new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) );
		$help     = new Help( new EligibilityResolver( new RequestRepository() ), new HelpContextBuilder( $timeline ) );

		$context = $help->context_for( $order )->submitted(
			'where_is_my_order',
			'Where is my order?',
			'It has not arrived. ' . $payload,
			Help::SOURCE_ACCOUNT,
			$order->get_edit_order_url()
		);

		$email = new HelpRequest();

		$email->trigger( $context, $order );

		$html  = $email->get_content_html();
		$plain = $email->get_content_plain();

		foreach ( array(
			'html'  => $html,
			'plain' => $plain,
		) as $format => $content ) {
			$this->assertNotSame( '', $content, 'The ' . $format . ' template rendered nothing.' );
			$this->assertStringNotContainsString( '<script>', $content, $format . ' must not contain a raw <script> tag.' );
			$this->assertStringContainsString( 'It has not arrived.', $content, $format . ' carries the message.' );
			$this->assertStringContainsString( (string) $order->get_order_number(), $content, $format . ' names the order.' );
		}

		$this->assertSame( get_option( 'admin_email' ), $email->get_recipient() );
	}
}
