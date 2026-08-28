<?php
/**
 * Customer email confirming an approved request.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Requests\Request;

/**
 * Sent to the customer once a staff member approves their request.
 *
 * For a cancellation this fires after `Actions\Cancel::approve()` has already
 * transitioned the order — this email only ever reports what happened, it
 * never triggers a refund and never implies one is automatic. CLAUDE.md hard
 * rule 8: this plugin issues no refund in v1, ever.
 *
 * @since 0.10.0
 */
final class RequestApproved extends AbstractEmail {

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
		$this->id             = 'pph_request_approved';
		$this->customer_email = true;
		$this->title          = __( 'Request approved', 'wpmake-post-purchase-hub' );
		$this->description    = __( 'Sent to the customer once a staff member approves their cancellation request.', 'wpmake-post-purchase-hub' );
		$this->email_group    = 'order-changes';
		$this->template_html  = 'emails/request-approved.php';
		$this->template_plain = 'emails/plain/request-approved.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'pph_request_approved', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( 'Your request for order {order_number} has been approved', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'Your request has been approved', 'wpmake-post-purchase-hub' );
	}

	/**
	 * Trigger. Hooked to `pph_request_approved`.
	 *
	 * @since 0.10.0
	 *
	 * @param Request        $request Request just approved.
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
		return __( 'If a refund applies, it will be processed separately and you will receive a confirmation from us.', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field() + $this->content_fields();
	}
}
