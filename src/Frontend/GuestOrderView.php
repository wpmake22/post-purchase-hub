<?php
/**
 * Renders one order to a guest holding a signed-link context.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\Sanitizer;

/**
 * Makes the last hop of the guest journey work: the emailed link actually
 * opening the order.
 *
 * Without this class the whole signed-link feature is decorative.
 * `Emails\SecureLink::url()` points at the My Account `view-order` endpoint, and
 * `WC_Shortcode_My_Account::output()` answers a logged-out visitor with
 * `myaccount/form-login.php` before that endpoint is ever consulted — there is
 * no filter on that branch of core to ask it politely. A guest with a perfectly
 * valid token would arrive at a login form they have no password for, which is
 * the one outcome the entire feature exists to avoid.
 *
 * So the login form's template is substituted, and only under conditions that
 * are each individually necessary: the visitor is logged out, they are on the
 * `view-order` endpoint, and `Security\OwnershipResolver` — the single ownership
 * choke point, reading the context `Frontend\GuestContext` established — says
 * they may reach that specific order. Any one of those failing leaves core's
 * login form exactly where it was. Nothing here decides access; it asks.
 *
 * Substitution goes through `wc_get_template`, the same documented filter
 * `Frontend\TemplateReplacer` already uses, so this is not a new mechanism.
 * What it cannot do is pass a view model: `wc_get_template()` extracts the
 * arguments its *caller* supplied, and the caller here is core rendering a
 * login form with none. Hence the hand-off template — see
 * `templates/myaccount/guest-order-handoff.php`.
 *
 * @since 0.11.0
 */
final class GuestOrderView {

	/**
	 * Core template this class stands in for.
	 *
	 * @var string
	 */
	public const LOGIN_TEMPLATE = 'myaccount/form-login.php';

	/**
	 * WooCommerce query var carrying the order being viewed.
	 *
	 * @var string
	 */
	private const ENDPOINT = 'view-order';

	/**
	 * Order this request is entitled to see, once resolved.
	 *
	 * @var \WC_Order|null
	 */
	private ?\WC_Order $order = null;

	/**
	 * Whether entitlement has already been decided this request.
	 *
	 * Separate from `$order` because "no order" is an answer worth caching:
	 * core's notice hook and its template hook both ask, and asking the
	 * ownership resolver twice would double the work and double the log lines
	 * for one visitor's single request.
	 *
	 * @var bool
	 */
	private bool $resolved = false;

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param OwnershipResolver $ownership The one place order access is decided.
	 * @param TemplateLoader    $templates Template loader.
	 */
	public function __construct(
		private OwnershipResolver $ownership,
		private TemplateLoader $templates
	) {}

	/**
	 * Wires the substitution and the hand-off.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wc_get_template', array( $this, 'replace_login_form' ), 10, 2 );
		add_action( 'pph_render_guest_order', array( $this, 'render' ) );
		add_filter( 'woocommerce_my_account_message', array( $this, 'explain_dead_link' ) );
	}

	/**
	 * Tells a guest why their link did not open an order.
	 *
	 * Without this, the two ways a signed link can fail to land — the token no
	 * longer verifies, or the browser refused the context cookie — both end at
	 * a login form with no explanation, which reads as a broken store rather
	 * than an expired link. `woocommerce_my_account_message` is core's own hook
	 * for exactly this, and core applies it only when the visitor is logged
	 * out, which is the only case that needs it.
	 *
	 * Says nothing about any order: the message is chosen from the outcome
	 * `GuestContext` recorded on the redirect, never from an order lookup.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $message Message another callback already set, if any.
	 * @return string
	 */
	public function explain_dead_link( $message ): string {
		$existing = is_string( $message ) ? $message : '';

		// Resolved rather than read off a field: core applies this hook while
		// adding notices, which happens before it fetches the login template,
		// so nothing has decided entitlement yet at this point.
		if ( '' !== $existing || is_user_logged_in() || null !== $this->entitled_order() ) {
			return $existing;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing between two fixed messages on a GET; nothing is read from the order and nothing is written.
		$state = isset( $_GET[ GuestContext::STATE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ GuestContext::STATE_PARAM ] ) ) : '';

		if ( GuestContext::STATE_EXPIRED === $state ) {
			return __( 'That order link has expired or is no longer valid. Request a new one and we will email it to the address on the order.', 'post-purchase-hub' );
		}

		if ( GuestContext::STATE_READY === $state ) {
			// The exchange succeeded but no context reached this request, which
			// leaves one explanation: the browser did not keep the cookie.
			return __( 'Your browser did not keep the session cookie this order link needs. Enable cookies for this site and open the link again.', 'post-purchase-hub' );
		}

		return '';
	}

	/**
	 * Substitutes the login form when this guest may see the order behind it.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $template      Absolute path WooCommerce resolved.
	 * @param mixed $template_name Template name being fetched.
	 * @return mixed
	 */
	public function replace_login_form( $template, $template_name = '' ) {
		if ( self::LOGIN_TEMPLATE !== $template_name || is_user_logged_in() ) {
			return $template;
		}

		if ( null === $this->entitled_order() ) {
			return $template;
		}

		// An order page, for a visitor identified by a bearer credential. Never
		// cacheable, whoever else asks for this URL next.
		Sanitizer::nocache();

		$handoff = PPH_PLUGIN_DIR . 'templates/myaccount/guest-order-handoff.php';

		return is_readable( $handoff ) ? $handoff : $template;
	}

	/**
	 * Renders the order. Hooked to `pph_render_guest_order`.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->order instanceof \WC_Order ) {
			return;
		}

		$this->templates->render(
			'myaccount/guest-order.php',
			array(
				'order'        => $this->order,
				'order_id'     => $this->order->get_id(),
				'order_number' => (string) $this->order->get_order_number(),
				'status_label' => wc_get_order_status_name( $this->order->get_status() ),
				'placed_on'    => wc_format_datetime( $this->order->get_date_created() ),
			)
		);
	}

	/**
	 * The order this request may see, or null.
	 *
	 * @since 0.11.0
	 * @return \WC_Order|null
	 */
	private function entitled_order(): ?\WC_Order {
		if ( $this->resolved ) {
			return $this->order;
		}

		$this->resolved = true;

		$order_id = (int) get_query_var( self::ENDPOINT );

		if ( $order_id < 1 ) {
			return null;
		}

		try {
			$this->order = $this->ownership->assertCanAccess( $order_id, 'frontend:guest-order-view' ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method name fixed by Security\OwnershipResolver.
		} catch ( AccessDeniedException $e ) {
			unset( $e );

			// Silently, and with core's own login form as the answer: a guest
			// who learns that order 41 exists but is not theirs has learned
			// something docs/SPEC.md Phase 8 does not let them learn.
			return null;
		}

		return $this->order;
	}
}
