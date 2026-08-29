<?php
/**
 * Customer email confirming a request was received.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Requests\Request;

/**
 * Sent to the customer the moment a request is created, so "did they get my
 * request?" never has to become a support ticket.
 *
 * Copy requirement (docs/SPEC.md Phase 9, CLAUDE.md hard rule 15's sibling in
 * spirit): this confirms a *request* was received, with the expected response
 * time, never that anything has been cancelled.
 *
 * @since 0.10.0
 */
final class RequestReceived extends AbstractEmail {

	/**
	 * The request this instance is reporting on. Set by trigger(); templates
	 * receive it explicitly rather than reaching for this property.
	 *
	 * @var Request|null
	 */
	private ?Request $request = null;

	/**
	 * {@inheritDoc}
	 */
	public function __construct() {
		$this->id             = 'wpmphub_request_received';
		$this->customer_email = true;
		$this->title          = __( 'Request received', 'wpmake-post-purchase-hub' );
		$this->description    = __( 'Sent to the customer the moment they raise a cancellation request, confirming it was received and when to expect a reply.', 'wpmake-post-purchase-hub' );
		$this->email_group    = 'order-changes';
		$this->template_html  = 'emails/request-received.php';
		$this->template_plain = 'emails/plain/request-received.php';
		$this->placeholders   = array(
			'{order_number}'  => '',
			'{response_time}' => '',
		);

		add_action( 'wpmphub_request_created', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( 'We received your request about order {order_number}', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'We received your request', 'wpmake-post-purchase-hub' );
	}

	/**
	 * Trigger. Hooked to `wpmphub_request_created`.
	 *
	 * @since 0.10.0
	 *
	 * @param Request        $request Request just created.
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

		$this->recipient                       = $order->get_billing_email();
		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{response_time}'] = self::response_time_text();

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
				'response_time_text' => self::response_time_text(),
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
				'response_time_text' => self::response_time_text(),
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
		return __( 'Thanks for your patience while we take a look.', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field() + $this->content_fields();
	}

	/**
	 * The expected response time as customer-facing copy.
	 *
	 * @since 0.10.0
	 * @return string
	 */
	private static function response_time_text(): string {
		$hours = Cancel::response_time_hours();

		if ( 0 === $hours % 24 ) {
			$days = (int) ( $hours / 24 );

			/* translators: %d: number of days. */
			return sprintf( _n( '%d day', '%d days', $days, 'wpmake-post-purchase-hub' ), $days );
		}

		/* translators: %d: number of hours. */
		return sprintf( _n( '%d hour', '%d hours', $hours, 'wpmake-post-purchase-hub' ), $hours );
	}
}
