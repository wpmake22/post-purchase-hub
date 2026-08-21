<?php
/**
 * Opt-in secure link inside other WooCommerce transactional emails.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Security\TokenService;

/**
 * Lets a merchant add this plugin's secure order link to one of
 * WooCommerce's own customer emails — "your order is on its way" being the
 * obvious candidate — instead of only ever sending it from this plugin's own
 * `SecureOrderLink` email.
 *
 * Off by default, and opt-in per email type (docs/SPEC.md Milestone 10, task
 * 5): rather than inventing a settings screen this milestone does not own,
 * the toggle is added to each target email's own settings via
 * `woocommerce_settings_api_form_fields_{id}`, so it is stored, rendered and
 * persisted entirely by WooCommerce's existing Settings API — the same
 * mechanism `pph_registered_emails` lets our own emails reuse.
 *
 * @since 0.10.0
 */
final class LinkInjector {

	/**
	 * Filters which core email ids may carry the opt-in checkbox.
	 *
	 * @var string
	 */
	public const TARGETS_FILTER = 'pph_email_link_injection_targets';

	/**
	 * The checkbox's own settings key, stored on the target email itself.
	 *
	 * @var string
	 */
	public const SETTINGS_FIELD = 'pph_include_secure_link';

	/**
	 * Constructor.
	 *
	 * @since 0.10.0
	 *
	 * @param TokenService $tokens Issues the token the link carries.
	 */
	public function __construct( private TokenService $tokens ) {}

	/**
	 * Wires the settings field and the injection point.
	 *
	 * @since 0.10.0
	 * @return void
	 */
	public function register(): void {
		foreach ( self::targets() as $email_id ) {
			add_filter( "woocommerce_settings_api_form_fields_{$email_id}", array( $this, 'add_settings_field' ) );
		}

		add_action( 'woocommerce_email_before_order_table', array( $this, 'maybe_inject' ), 5, 4 );
	}

	/**
	 * Adds the opt-in checkbox to a target email's own settings screen.
	 *
	 * @since 0.10.0
	 *
	 * @param array<string, array<string, mixed>> $fields The target email's existing fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_settings_field( array $fields ): array {
		$fields[ self::SETTINGS_FIELD ] = array(
			'title'       => __( 'Post-Purchase Hub secure link', 'post-purchase-hub' ),
			'type'        => 'checkbox',
			'label'       => __( 'Include a secure, no-login link to this order', 'post-purchase-hub' ),
			'description' => __( 'Lets the customer view this order without signing in. Off by default.', 'post-purchase-hub' ),
			'default'     => 'no',
			'desc_tip'    => true,
		);

		return $fields;
	}

	/**
	 * Injects the link into a target email that has opted in.
	 *
	 * Hooked at priority 5 on `woocommerce_email_before_order_table`, ahead of
	 * the order table itself, so the link reads as part of the email's own
	 * introduction rather than an afterthought below the line items.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order|mixed $order         Order the email concerns.
	 * @param bool            $sent_to_admin Whether this copy is the admin's.
	 * @param bool            $plain_text    Whether this is the plain-text part.
	 * @param mixed           $email         The WC_Email instance rendering.
	 * @return void
	 */
	public function maybe_inject( $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! $email instanceof \WC_Email ) {
			return;
		}

		// Our own emails carry their own link where one applies; never double it.
		if ( $email instanceof AbstractEmail ) {
			return;
		}

		if ( ! in_array( $email->id, self::targets(), true ) ) {
			return;
		}

		if ( 'yes' !== $email->get_option( self::SETTINGS_FIELD, 'no' ) ) {
			return;
		}

		wc_get_template(
			'emails/partials/secure-link-notice.php',
			array(
				'link_url'   => SecureLink::url( $order, $this->tokens ),
				'plain_text' => $plain_text,
			),
			TemplateLoader::THEME_DIRECTORY,
			PPH_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * The core email ids that may carry the opt-in checkbox.
	 *
	 * @since 0.10.0
	 * @return string[]
	 */
	public static function targets(): array {
		$defaults = array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order' );

		// Literal string below, not self::TARGETS_FILTER: PHPCS's hook-prefix
		// check cannot resolve a class-constant hook name, and the two must
		// stay identical.
		/**
		 * Filters which core WooCommerce email ids may opt in to carrying this
		 * plugin's secure order link. Defaults to none injected until a
		 * merchant explicitly enables one of these on its own settings screen —
		 * this filter controls availability of the checkbox, not its default.
		 *
		 * @since 0.10.0
		 *
		 * @param string[] $targets Core email ids eligible for the opt-in checkbox.
		 */
		$targets = apply_filters( 'pph_email_link_injection_targets', $defaults );

		if ( ! is_array( $targets ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $target ): string {
						return is_scalar( $target ) ? sanitize_key( (string) $target ) : '';
					},
					$targets
				)
			)
		);
	}
}
