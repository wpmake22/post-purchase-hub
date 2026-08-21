<?php
/**
 * The one admin notice this plugin shows.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Install\SetupState;

/**
 * Points a merchant at the wizard, once, on the screens where it makes sense.
 *
 * Deliberately the only notice in the plugin, and deliberately narrow: it shows
 * on WooCommerce and plugin screens rather than every page of wp-admin, it
 * stops the moment setup is finished, and dismissing it is remembered per user.
 * A plugin that nags on every screen is a plugin whose notices get ignored, and
 * the notice that matters here is the one saying "nothing is live yet" — which
 * is only true, and only actionable, before the wizard is done.
 *
 * Dismissal is a POST, not a link (CLAUDE.md hard rule 4: no state mutation on
 * GET, ever). Core's own dismissible notices are usually GET links; this one is
 * a one-button form to `admin-post.php`, and the handler refuses anything that
 * is not a POST as well as anything without the nonce — because a rule enforced
 * only in the markup is not enforced.
 *
 * @since 0.14.0
 */
final class Notices {

	/**
	 * User meta holding the dismissal.
	 *
	 * @var string
	 */
	public const DISMISSED_META = 'pph_setup_notice_dismissed';

	/**
	 * Capability that sees the notice at all.
	 *
	 * @var string
	 */
	public const CAPABILITY = SettingsPage::CAPABILITY;

	/**
	 * The admin-post.php action that dismisses it.
	 *
	 * @var string
	 */
	public const DISMISS_ACTION = 'pph_dismiss_setup_notice';

	/**
	 * Nonce action for the dismissal.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pph_dismiss_notice';

	/**
	 * Screen id prefixes the notice is allowed on.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'dashboard', 'plugins', 'woocommerce', 'shop_order', 'edit-shop_order' );

	/**
	 * Wires the notice and its dismissal handler.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Renders the notice when this screen and this user should see it.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		echo '<div class="notice notice-info pph-notice" data-pph-setup-notice>';

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Post-Purchase Hub is installed.', 'post-purchase-hub' ),
			esc_html__( 'Nothing is showing to your customers yet. The setup wizard takes about two minutes and asks four questions.', 'post-purchase-hub' )
		);

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( Wizard::url() ),
			esc_html__( 'Run the setup wizard', 'post-purchase-hub' )
		);

		printf(
			' <form method="post" action="%1$s" class="pph-notice__dismiss" data-pph-notice-dismiss>',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( self::NONCE_ACTION );

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::DISMISS_ACTION ) );
		printf( '<input type="hidden" name="redirect" value="%s" />', esc_attr( self::current_path() ) );
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Not now', 'post-purchase-hub' ) );

		echo '</form></p></div>';
	}

	/**
	 * Whether to draw it.
	 *
	 * @since 0.14.0
	 * @return bool
	 */
	private function should_render(): bool {
		if ( SetupState::is_complete() || ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		if ( self::is_dismissed( get_current_user_id() ) ) {
			return false;
		}

		// The wizard's own screens do not need a notice telling them to be the
		// wizard.
		return ! self::on_our_own_screens() && self::on_a_relevant_screen();
	}

	/**
	 * Whether this user has dismissed it.
	 *
	 * @since 0.14.0
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_dismissed( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::DISMISSED_META, true );
	}

	/**
	 * Handles the dismissal, then sends the merchant back where they were.
	 *
	 * @since 0.14.0
	 * @return void
	 */
	public function dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'post-purchase-hub' ), '', array( 'response' => 403 ) );
		}

		// `admin_post_{action}` fires for GET as well as POST, and this writes
		// user meta: hard rule 4 says a GET never mutates, so this is where
		// that is enforced rather than assumed from the form's method.
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'That request could not be completed.', 'post-purchase-hub' ), '', array( 'response' => 405 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line; read here only to hand to the verifier.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'That link has expired. Please try again.', 'post-purchase-hub' ), '', array( 'response' => 403 ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_META, gmdate( 'Y-m-d H:i:s' ) );

		// No exit: admin-post.php ends the request once this action has run,
		// and returning is what lets the handler be tested at all — matching
		// Admin\RequestActionController.
		wp_safe_redirect( self::return_url() );
	}

	/**
	 * Where the dismissal should return to: the screen it was clicked on, or
	 * the dashboard when that cannot be established.
	 *
	 * @since 0.14.0
	 * @return string
	 */
	private static function return_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified by the caller; this only picks a redirect target.
		$requested = isset( $_REQUEST['redirect'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_REQUEST['redirect'] ) ) ) : '';

		// Matched against the shape of an admin screen rather than trusted and
		// concatenated. `admin_url()` would keep an arbitrary value on this
		// host either way, but it would keep it as nonsense — and a redirect
		// target taken from a request is worth pattern-matching, not patching
		// afterwards.
		if ( 1 !== preg_match( '#^[a-z0-9_-]+\.php(\?page=[a-z0-9_-]+)?$#', ltrim( $requested, '/' ) ) ) {
			return admin_url();
		}

		return admin_url( ltrim( $requested, '/' ) );
	}

	/**
	 * The current admin path, for the round trip.
	 *
	 * @since 0.14.0
	 * @return string
	 */
	public static function current_path(): string {
		global $pagenow;

		$page = is_string( $pagenow ) && '' !== $pagenow ? $pagenow : 'index.php';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen's own page slug to return to it; not a state change.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' === $slug ? $page : $page . '?page=' . $slug;
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @since 0.14.0
	 * @return bool
	 */
	private static function on_our_own_screens(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding whether to draw a notice on a GET; not a state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return in_array( $page, array( Wizard::PAGE, SettingsPage::PAGE ), true );
	}

	/**
	 * Whether the current screen is one a merchant would expect this on.
	 *
	 * @since 0.14.0
	 * @return bool
	 */
	private static function on_a_relevant_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		foreach ( self::SCREENS as $allowed ) {
			if ( $screen->id === $allowed || str_contains( $screen->id, $allowed ) ) {
				return true;
			}
		}

		return false;
	}
}
