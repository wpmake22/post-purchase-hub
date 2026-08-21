<?php
/**
 * WC_Email stub for the unit suite.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

require_once __DIR__ . '/wc-settings-api.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Email' ) ) {
	/**
	 * Bare-bones stand-in for WooCommerce's `WC_Email`. Deliberately does not
	 * attempt template rendering (`wc_get_template_html()` and friends) —
	 * that pipeline is exercised in the integration suite against the real
	 * WooCommerce, not stubbed here. `send()` records what would have gone
	 * out rather than delivering it, so a unit test can assert on recipient,
	 * subject and whether a send happened at all.
	 */
	class WC_Email extends WC_Settings_API {

		/**
		 * Skip-reason identifier used when the email has no recipient address.
		 *
		 * @var string
		 */
		public const SKIP_REASON_NO_RECIPIENT = 'no_recipient';

		/**
		 * Email method title.
		 *
		 * @var string
		 */
		public $title = '';

		/**
		 * Email description shown on the settings screen.
		 *
		 * @var string
		 */
		public $description = '';

		/**
		 * Configured heading override, empty when unconfigured.
		 *
		 * @var string
		 */
		public $heading = '';

		/**
		 * Configured subject override, empty when unconfigured.
		 *
		 * @var string
		 */
		public $subject = '';

		/**
		 * HTML template path, relative to the email's own template base.
		 *
		 * @var string
		 */
		public $template_html = '';

		/**
		 * Plain-text template path, relative to the email's own template base.
		 *
		 * @var string
		 */
		public $template_plain = '';

		/**
		 * Base path template lookups resolve against.
		 *
		 * @var string|null
		 */
		public $template_base = null;

		/**
		 * Whether this is a customer-facing email.
		 *
		 * @var bool
		 */
		public $customer_email = false;

		/**
		 * Whether this email is only ever triggered manually.
		 *
		 * @var bool
		 */
		protected $manual = false;

		/**
		 * Settings-screen grouping; cosmetic only.
		 *
		 * @var string
		 */
		public $email_group = '';

		/**
		 * Find/replace placeholders substituted into subject, heading and
		 * additional content.
		 *
		 * @var array<string, string>
		 */
		public $placeholders = array();

		/**
		 * Resolved recipient address(es), comma-separated.
		 *
		 * @var string
		 */
		public $recipient = '';

		/**
		 * The order (or other subject) this instance is currently rendering for.
		 *
		 * @var object|null
		 */
		public $object = null;

		/**
		 * Real WC_Email merges site-wide placeholders here before
		 * init_form_fields()/init_settings() run; the stub does the same so a
		 * subclass's own placeholders survive the merge in the same order.
		 */
		public function __construct() {
			$this->placeholders = array_merge(
				array(
					'{site_title}' => 'Example Store',
				),
				$this->placeholders
			);

			$this->init_form_fields();
			$this->init_settings();

			$this->subject = (string) $this->get_option( 'subject', '' );
			$this->heading = (string) $this->get_option( 'heading', '' );
		}

		/**
		 * The configured or default heading, with placeholders substituted.
		 *
		 * @return string
		 */
		public function get_heading() {
			$heading = '' !== $this->heading ? $this->heading : $this->get_default_heading();

			return $this->format_string( $heading );
		}

		/**
		 * The configured or default subject, with placeholders substituted.
		 *
		 * @return string
		 */
		public function get_subject() {
			$subject = '' !== $this->subject ? $this->subject : $this->get_default_subject();

			return $this->format_string( $subject );
		}

		/**
		 * The default heading. Real subclasses override this.
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return $this->heading;
		}

		/**
		 * The default subject. Real subclasses override this.
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return $this->subject;
		}

		/**
		 * The default additional content. Real subclasses override this.
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return '';
		}

		/**
		 * The configured or default additional content, with placeholders
		 * substituted.
		 *
		 * @return string
		 */
		public function get_additional_content() {
			return $this->format_string( (string) $this->get_option( 'additional_content', $this->get_default_additional_content() ) );
		}

		/**
		 * Substitutes this email's placeholders into a string.
		 *
		 * @param string $subject String to substitute placeholders into.
		 * @return string
		 */
		public function format_string( $subject ) {
			return str_replace( array_keys( $this->placeholders ), array_values( $this->placeholders ), (string) $subject );
		}

		/**
		 * The email-type select's own options.
		 *
		 * @return array<string, string>
		 */
		public function get_email_type_options() {
			return array(
				'plain' => 'Plain text',
				'html'  => 'HTML',
			);
		}

		/**
		 * Whether this email is enabled.
		 *
		 * @return bool
		 */
		public function is_enabled() {
			return 'yes' === $this->get_option( 'enabled', 'yes' );
		}

		/**
		 * Whether this is a customer-facing email.
		 *
		 * @return bool
		 */
		public function is_customer_email() {
			return $this->customer_email;
		}

		/**
		 * Whether this email is only ever triggered manually.
		 *
		 * @return bool
		 */
		public function is_manual() {
			return $this->manual;
		}

		/**
		 * The resolved recipient list, comma-separated.
		 *
		 * @return string
		 */
		public function get_recipient() {
			$recipients = array_filter( array_map( 'trim', explode( ',', (string) $this->recipient ) ) );

			return implode( ', ', $recipients );
		}

		/**
		 * Site locale switching. The stub has no locale stack of its own to
		 * fall back to — `Emails\AbstractEmail` overrides both methods
		 * entirely for a customer email with an order, which is the only path
		 * these tests exercise.
		 *
		 * @return void
		 */
		public function setup_locale() {}

		/**
		 * Restores whatever setup_locale() switched. No-op in the stub.
		 *
		 * @return void
		 */
		public function restore_locale() {}

		/**
		 * Records the send rather than delivering it.
		 *
		 * @param string $to          Recipient.
		 * @param string $subject     Subject.
		 * @param string $message     Body.
		 * @param mixed  $headers     Headers.
		 * @param mixed  $attachments Attachments.
		 * @return bool
		 */
		public function send( $to, $subject, $message, $headers, $attachments ) {
			\PostPurchaseHub\Tests\Unit\Support\FakeWordPress::$sent_emails[] = array(
				'id'      => $this->id,
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
			);

			return true;
		}

		/**
		 * Sends when enabled and a recipient is available.
		 *
		 * @return bool
		 */
		protected function send_notification() {
			if ( ! $this->is_enabled() ) {
				return false;
			}

			return $this->send_if_recipient();
		}

		/**
		 * Sends when a recipient is available, bypassing the enabled gate —
		 * matching real WC_Email's own helper for manually-triggered emails.
		 *
		 * @return bool
		 */
		protected function send_if_recipient() {
			$recipient = $this->get_recipient();

			if ( '' === $recipient ) {
				return false;
			}

			return $this->send( $recipient, $this->get_subject(), '', array(), array() );
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
