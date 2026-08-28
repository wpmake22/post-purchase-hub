<?php
/**
 * Shared base for this plugin's WC_Email subclasses.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Admin\Menu;
use PostPurchaseHub\Frontend\TemplateLoader;

/**
 * What every request-lifecycle email needs beyond stock `WC_Email`.
 *
 * Two things live here rather than being repeated per class:
 *
 * 1. Template resolution through this plugin's own theme-override convention
 *    (`yourtheme/wpmake-post-purchase-hub/emails/...`) rather than WooCommerce's
 *    (`yourtheme/woocommerce/emails/...`), so a merchant who already overrides
 *    our timeline and action partials finds our emails in the same place.
 * 2. Order-derived locale switching. `WC_Email::setup_locale()` always
 *    switches a customer email to the *site's* locale; this overrides that for
 *    exactly the customer emails that have an order to derive one from, per
 *    docs/SPEC.md Milestone 10 ("Locale resolved from the order, not the
 *    current request") — see `LocaleResolver` for the resolution order and
 *    its one documented assumption.
 *
 * @since 0.10.0
 */
abstract class AbstractEmail extends \WC_Email {

	/**
	 * Whether this instance switched the active locale and still owes a restore.
	 *
	 * @var bool
	 */
	private bool $pph_locale_switched = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.10.0
	 */
	public function __construct() {
		$this->template_base = PPH_PLUGIN_DIR . 'templates/';

		parent::__construct();
	}

	/**
	 * The template-path argument every `wc_get_template_html()` call here uses,
	 * so a theme override lands at `yourtheme/wpmake-post-purchase-hub/emails/...`
	 * rather than the WooCommerce convention this plugin's other templates
	 * already depart from (`Frontend\TemplateLoader::THEME_DIRECTORY`).
	 *
	 * @since 0.10.0
	 *
	 * @return string
	 */
	final protected function theme_override_path(): string {
		return TemplateLoader::THEME_DIRECTORY;
	}

	/**
	 * Switches to the order's own locale for a customer email, instead of the
	 * site locale `WC_Email::setup_locale()` would otherwise pick.
	 *
	 * Only customer emails switch at all — matching the guard
	 * `WC_Email::setup_locale()` itself uses — and only once this instance has
	 * an order to resolve a locale from; a trigger that has not yet set
	 * `$this->object` falls through to the parent behaviour rather than
	 * switching to nothing.
	 *
	 * @since 0.10.0
	 * @return void
	 */
	public function setup_locale(): void {
		if ( ! $this->is_customer_email() || ! $this->object instanceof \WC_Order ) {
			parent::setup_locale();

			return;
		}

		$this->pph_locale_switched = switch_to_locale( LocaleResolver::for_order( $this->object ) );
	}

	/**
	 * Restores whatever `setup_locale()` switched away from.
	 *
	 * @since 0.10.0
	 * @return void
	 */
	public function restore_locale(): void {
		if ( ! $this->pph_locale_switched ) {
			parent::restore_locale();

			return;
		}

		restore_current_locale();

		$this->pph_locale_switched = false;
	}

	/**
	 * A deep link into the admin request queue for one request.
	 *
	 * @since 0.10.0
	 *
	 * @param int $request_id Request id.
	 * @return string
	 */
	final protected function request_admin_url( int $request_id ): string {
		return add_query_arg(
			array(
				'page'       => Menu::REQUESTS_PAGE,
				'request_id' => $request_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The `enabled` settings field every email carries.
	 *
	 * `WC_Settings_API::init_form_fields()` is a no-op in the base class — each
	 * concrete `WC_Email` is expected to define its whole `form_fields` array,
	 * per WooCommerce's own email classes. These three helpers exist so this
	 * plugin's six emails compose that array from shared pieces instead of
	 * repeating the same field definitions six times.
	 *
	 * @since 0.10.0
	 * @return array<string, array<string, mixed>>
	 */
	final protected function enabled_field(): array {
		return array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'wpmake-post-purchase-hub' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'wpmake-post-purchase-hub' ),
				'default' => 'yes',
			),
		);
	}

	/**
	 * The `recipient` field for an admin-facing email.
	 *
	 * @since 0.10.0
	 *
	 * @param string $default_recipient Address shown as the placeholder default.
	 * @return array<string, array<string, mixed>>
	 */
	final protected function recipient_field( string $default_recipient ): array {
		return array(
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'wpmake-post-purchase-hub' ),
				'type'        => 'text',
				/* translators: %s: default recipient address. */
				'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'wpmake-post-purchase-hub' ), '<code>' . esc_attr( $default_recipient ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * The subject, heading, additional-content and email-type fields every
	 * email carries, in the order WooCommerce's own settings screen expects.
	 *
	 * @since 0.10.0
	 * @return array<string, array<string, mixed>>
	 */
	final protected function content_fields(): array {
		$placeholder_text = array() === $this->placeholders
			? ''
			: sprintf(
				/* translators: %s: list of placeholders */
				__( 'Available placeholders: %s', 'wpmake-post-purchase-hub' ),
				'<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>'
			);

		return array(
			'subject'            => array(
				'title'       => __( 'Subject', 'wpmake-post-purchase-hub' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'            => array(
				'title'       => __( 'Email heading', 'wpmake-post-purchase-hub' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'wpmake-post-purchase-hub' ),
				'description' => __( 'Text to appear below the main email content.', 'wpmake-post-purchase-hub' ) . ' ' . $placeholder_text,
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'wpmake-post-purchase-hub' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'wpmake-post-purchase-hub' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'wpmake-post-purchase-hub' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
}
