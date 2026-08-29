<?php
/**
 * Admin email announcing a new customer request.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Requests\Request;

/**
 * Sent to the merchant the moment a customer raises a request.
 *
 * The spec's own case for this: "A cancellation request the merchant does not
 * see for six hours is worse than an email." (docs/SPEC.md). This is the
 * admin-side half of that; the menu bubble `Admin\Menu::pending_count()`
 * builds is the other.
 *
 * @since 0.10.0
 */
final class NewRequestAdmin extends AbstractEmail {

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
		$this->id             = 'wpmphub_admin_new_request';
		$this->customer_email = false;
		$this->title          = __( 'New request', 'wpmake-post-purchase-hub' );
		$this->description    = __( 'Sent to the store when a customer raises a cancellation request.', 'wpmake-post-purchase-hub' );
		$this->email_group    = 'orders';
		$this->template_html  = 'emails/admin-new-request.php';
		$this->template_plain = 'emails/plain/admin-new-request.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'wpmphub_request_created', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] New request for order {order_number}', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'A customer has raised a request', 'wpmake-post-purchase-hub' );
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

		$this->setup_locale();

		$this->placeholders['{order_number}'] = $order instanceof \WC_Order ? $order->get_order_number() : (string) $request->order_id;

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
				'reason_label'       => self::reason_label( $this->request ),
				'queue_url'          => $this->request_admin_url( $this->request->id ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
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
				'reason_label'       => self::reason_label( $this->request ),
				'queue_url'          => $this->request_admin_url( $this->request->id ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
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
	public function init_form_fields(): void {
		$this->form_fields = $this->enabled_field()
			+ $this->recipient_field( (string) get_option( 'admin_email' ) )
			+ $this->content_fields();
	}

	/**
	 * The reason label for the template, computed here so the template stays
	 * logic-free (CLAUDE.md hard rule 10) — it only receives a prepared
	 * string, never `Actions\Cancel`'s reason-code vocabulary.
	 *
	 * Null for any request type other than cancellation, the only type with a
	 * mapped reason vocabulary today.
	 *
	 * @since 0.10.0
	 *
	 * @param Request $request Request to label.
	 * @return string|null
	 */
	private static function reason_label( Request $request ): ?string {
		if ( Request::TYPE_CANCELLATION !== $request->type || null === $request->reason_code ) {
			return null;
		}

		$labels = Cancel::reason_code_labels();

		return $labels[ $request->reason_code ] ?? null;
	}
}
