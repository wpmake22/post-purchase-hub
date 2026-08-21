<?php
/**
 * Admin email carrying a customer's question about an order.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Actions\HelpContext;

/**
 * The destination of the Get Help form, and the reason it needs no settings
 * screen of its own.
 *
 * "Submits to a configurable recipient" (docs/MILESTONE-PROMPTS.md M13) is a
 * `WC_Email` recipient field: the merchant finds this in WooCommerce →
 * Settings → Emails with every other email the store sends, changes the
 * address there, edits the subject and heading there, and turns it off there.
 * Building a separate "support email address" setting would have meant a
 * second place to look for the same thing.
 *
 * Unlike this plugin's other emails, this one carries no `Requests\Request`:
 * a help submission is never stored, so everything the merchant reads comes
 * from the `HelpContext` the action assembled and handed to
 * `pph_help_submitted`.
 *
 * @since 0.13.0
 */
final class HelpRequest extends AbstractEmail {

	/**
	 * Email id, which also names its settings option.
	 *
	 * @var string
	 */
	public const ID = 'pph_help_request';

	/**
	 * The option `WC_Settings_API` stores this email's settings under.
	 *
	 * @var string
	 */
	public const SETTINGS_OPTION = 'woocommerce_' . self::ID . '_settings';

	/**
	 * The submission this instance is reporting.
	 *
	 * @var HelpContext|null
	 */
	private ?HelpContext $help = null;

	/**
	 * {@inheritDoc}
	 */
	public function __construct() {
		$this->id             = self::ID;
		$this->customer_email = false;
		$this->title          = __( 'Customer needs help', 'post-purchase-hub' );
		$this->description    = __( 'Sent to the store when a customer submits the help form on an order.', 'post-purchase-hub' );
		$this->email_group    = 'orders';
		$this->template_html  = 'emails/help-request.php';
		$this->template_plain = 'emails/plain/help-request.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'pph_help_submitted', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] Help needed with order {order_number}', 'post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'A customer has asked for help', 'post-purchase-hub' );
	}

	/**
	 * Trigger. Hooked to `pph_help_submitted`.
	 *
	 * @since 0.13.0
	 *
	 * @param HelpContext $context Submission and the order context it carries.
	 * @param \WC_Order   $order   Order the question is about.
	 * @return void
	 */
	public function trigger( HelpContext $context, \WC_Order $order ): void {
		$this->object = $order;
		$this->help   = $context;

		$this->setup_locale();

		$this->placeholders['{order_number}'] = $context->order_number;

		$this->send_notification();

		$this->restore_locale();
	}

	/**
	 * Whether a submission would be emailed anywhere.
	 *
	 * Read statically, from the option `WC_Settings_API` writes, because
	 * `Actions\Help` has to answer "is there anywhere to send this" while
	 * deciding whether to draw the form — on a storefront request where
	 * WooCommerce has not built its email registry and no instance of this
	 * class exists. An unset option means enabled, matching the `enabled`
	 * field's own default below.
	 *
	 * Deliberately not named `is_enabled()`: `WC_Email` already has an instance
	 * method by that name, reading the same setting off a constructed instance,
	 * and overriding it statically is not legal PHP. The two answer the same
	 * question from different places — this one before there is an instance to
	 * ask.
	 *
	 * @since 0.13.0
	 *
	 * @return bool
	 */
	public static function will_send(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) ) {
			return true;
		}

		return 'no' !== $settings['enabled'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args( false ),
			$this->theme_override_path(),
			$this->template_base
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args( true ),
			$this->theme_override_path(),
			$this->template_base
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field()
			+ $this->recipient_field( (string) get_option( 'admin_email' ) )
			+ $this->content_fields();
	}

	/**
	 * What both templates receive.
	 *
	 * @since 0.13.0
	 *
	 * @param bool $plain_text Whether the plain-text template is being rendered.
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'help'               => $this->help,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
