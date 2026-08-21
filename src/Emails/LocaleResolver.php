<?php
/**
 * Resolves the language an order-related email should be written in.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

/**
 * Decides an order's locale for customer-facing emails.
 *
 * WooCommerce's own `WC_Email::setup_locale()` deliberately switches every
 * customer email to the *site's* locale (`wc_switch_to_site_locale()`) so the
 * merchant's translated strings render correctly. That is the wrong choice on
 * a multilingual store: a customer who checked out in French must not receive
 * this plugin's cancellation-status emails in the site's default English. This
 * class exists to give `AbstractEmail::setup_locale()` an order-derived locale
 * to switch to instead.
 *
 * Resolution order:
 * 1. `pph_email_locale` filter — the documented override for any integration
 *    that knows the order's language better than the two guesses below.
 * 2. The `wpml_language` order meta key WPML/WooCommerce Multilingual writes
 *    at checkout. This is this class's one unverified assumption — flagged in
 *    the M10 report per CLAUDE.md's escalation rule, because a plugin whose
 *    meta shape differs (or a Polylang-only store, which does not write order
 *    meta at all) will not benefit from it until corrected through the filter.
 * 3. The registered customer's own account locale (`get_user_locale()`).
 * 4. The site's default locale, matching what a guest order would otherwise get.
 *
 * @since 0.10.0
 */
final class LocaleResolver {

	/**
	 * Order meta key WPML / WooCommerce Multilingual records the checkout
	 * language under. Read-only: adapters read tracking and language meta,
	 * they never write it (CLAUDE.md hard rule 9).
	 *
	 * @var string
	 */
	private const WPML_LANGUAGE_META = 'wpml_language';

	/**
	 * Resolves the locale an email about this order should be written in.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order $order Order the email concerns.
	 * @return string A locale string suitable for switch_to_locale(), e.g. `fr_FR`.
	 */
	public static function for_order( \WC_Order $order ): string {
		$locale = self::from_order_meta( $order );

		if ( null === $locale ) {
			$locale = self::from_customer_account( $order );
		}

		if ( null === $locale ) {
			$locale = get_locale();
		}

		/**
		 * Filters the locale resolved for an order-related email.
		 *
		 * The definitive override: a store running WPML, Polylang, or a custom
		 * checkout-language field should hook this rather than rely on this
		 * class's guesses above.
		 *
		 * @since 0.10.0
		 *
		 * @param string    $locale Locale resolved so far.
		 * @param \WC_Order $order  Order the email concerns.
		 */
		return (string) apply_filters( 'pph_email_locale', $locale, $order );
	}

	/**
	 * The language WPML / WooCommerce Multilingual recorded on the order, if any.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null
	 */
	private static function from_order_meta( \WC_Order $order ): ?string {
		$language = $order->get_meta( self::WPML_LANGUAGE_META );

		return is_string( $language ) && '' !== $language ? $language : null;
	}

	/**
	 * The registered customer's own account locale, if the order has one.
	 *
	 * @since 0.10.0
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null
	 */
	private static function from_customer_account( \WC_Order $order ): ?string {
		// get_user_id(), not the customer-id alias WooCommerce also offers for the
		// same value. What this method wants is the WordPress user whose locale
		// to read, and get_user_id() is the name for that. Ownership is not
		// being decided here — it is decided in exactly one place,
		// Security\OwnershipResolver, and the CI gate greps for the other
		// spelling so a reader can trust that. A locale lookup written in the
		// vocabulary of an access check makes the gate cry wolf.
		$customer_id = (int) $order->get_user_id();

		if ( $customer_id < 1 ) {
			return null;
		}

		$locale = get_user_locale( $customer_id );

		return '' !== $locale ? $locale : null;
	}
}
