<?php
/**
 * Customer email confirming a declined request.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Requests\Request;

/**
 * Sent to the customer once a staff member declines their request.
 *
 * Deliberately never renders `Request::$admin_note` — that field is internal
 * merchant context (see `Requests\RequestService::note_text()`'s docblock),
 * not customer-facing copy. What the customer sees is a plain notice plus the
 * store's own contact details, so a merchant who wants to explain further
 * does so by replying, not by this plugin echoing a note it never meant to
 * be read by them.
 *
 * @since 0.10.0
 */
final class RequestDeclined extends AbstractEmail {

	/**
	 * The request this instance is reporting on.
	 *
	 * @var Request|null
	 */
	private ?Request $request = null;

	/**
	 * {@inheritDoc}
	 */
	public function __construct() {
		$this->id             = 'wpmphub_request_declined';
		$this->customer_email = true;
		$this->title          = __( 'Request declined', 'wpmake-post-purchase-hub' );
		$this->description    = __( 'Sent to the customer once a staff member declines their cancellation request.', 'wpmake-post-purchase-hub' );
		$this->email_group    = 'order-changes';
		$this->template_html  = 'emails/request-declined.php';
		$this->template_plain = 'emails/plain/request-declined.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'wpmphub_request_declined', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( 'About your request for order {order_number}', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'We could not action your request', 'wpmake-post-purchase-hub' );
	}

	/**
	 * Trigger. Hooked to `wpmphub_request_declined`.
	 *
	 * @since 0.10.0
	 *
	 * @param Request        $request Request just declined.
	 * @param \WC_Order|null $order   Order it was raised against, if it still resolves.
	 * @return void
	 */
	public function trigger( Request $request, ?\WC_Order $order ): void {
		$this->object  = $order;
		$this->request = $request;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->setup_locale();

		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();

		$this->send_notification();

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
				'request'            => $this->request,
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
				'request'            => $this->request,
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
		return __( 'If you have questions about this decision, reply to this email and we will help.', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field() + $this->content_fields();
	}
}
