<?php
/**
 * Customer email carrying a signed order link.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Security\TokenService;

/**
 * Sends a customer a signed link to their order, on demand rather than on any
 * status change — hence `$manual = true`, the same flag core's own
 * `WC_Email_Customer_Invoice` carries for exactly the same reason.
 *
 * Nothing in this milestone calls `pph_secure_link_requested` yet: this class is
 * the receiving end of an extension point docs/SPEC.md's own Milestone 11
 * ("Guest Lookup & Signed Links") triggers from its send-link-on-failure
 * behaviour. Building the trigger here now, ahead of its first caller, means
 * M11 integrates by firing an action rather than by this milestone reaching
 * forward into a guest-lookup flow that does not exist yet.
 *
 * @since 0.10.0
 */
final class SecureOrderLink extends AbstractEmail {

	/**
	 * Constructor.
	 *
	 * @since 0.10.0
	 *
	 * @param TokenService $tokens Issues the token the link carries.
	 */
	public function __construct( private TokenService $tokens ) {
		$this->id             = 'pph_secure_link';
		$this->customer_email = true;
		$this->manual         = true;
		$this->title          = __( 'Secure order link', 'post-purchase-hub' );
		$this->description    = __( 'Sends the customer a secure, no-login link to their order. Triggered on demand — for example, when a guest asks to be sent one — never on a schedule.', 'post-purchase-hub' );
		$this->email_group    = 'order-changes';
		$this->template_html  = 'emails/secure-order-link.php';
		$this->template_plain = 'emails/plain/secure-order-link.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'pph_secure_link_requested', array( $this, 'trigger' ) );

		parent::__construct();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( 'Your secure link to order {order_number}', 'post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'Here is your secure order link', 'post-purchase-hub' );
	}

	/**
	 * Trigger. Hooked to `pph_secure_link_requested`.
	 *
	 * Deliberately `send_if_recipient()` rather than `send_notification()`: a
	 * manually-triggered email (`$this->manual`) is a merchant- or
	 * customer-initiated action, not a notification type a merchant switches
	 * off — WooCommerce's own `WC_Email_Customer_Invoice` follows the same
	 * rule for the same reason. The enabled toggle still governs whether the
	 * email appears configurable at all; it does not gate this send.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order $order Order to send a link for.
	 * @return void
	 */
	public function trigger( \WC_Order $order ): void {
		$this->object = $order;

		$this->setup_locale();

		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();

		$this->send_if_recipient();

		$this->restore_locale();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'link_url'           => SecureLink::url( $this->object, $this->tokens ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
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
			array(
				'order'              => $this->object,
				'link_url'           => SecureLink::url( $this->object, $this->tokens ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			$this->theme_override_path(),
			$this->template_base
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_additional_content(): string {
		return __( 'This link lets you view this order without signing in. Do not forward it.', 'post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field() + $this->content_fields();
	}
}
