<?php
/**
 * Order-number plus billing-email matching for guest lookup.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * Turns a submitted order number and email into an order, or into nothing.
 *
 * Both must match. The number is translated to an id through WooCommerce's own
 * `woocommerce_shortcode_order_tracking_order_id` filter — the hook every
 * custom-order-number plugin already implements for core's tracking shortcode —
 * so this class inherits that compatibility instead of inventing a second
 * convention nobody has heard of. Core has no
 * `wc_get_order_id_by_order_number()`; that filter plus the equality check
 * below is what the equivalent actually looks like.
 *
 * The number is then re-checked against the order's own `get_order_number()`.
 * That closes the raw-id path core's shortcode leaves open: on a store using a
 * custom numbering scheme, `wc_get_order( 4711 )` still loads order 4711 even
 * though its number is `INV-0032`, and without this check a visitor could walk
 * sequential ids. Woo order ids are guessable; order numbers on such a store
 * are the thing the customer was actually given.
 *
 * Nothing here shapes a response or logs an outcome. It answers one question —
 * does this pair identify an order — and the caller is responsible for
 * answering the visitor identically either way.
 *
 * @since 0.11.0
 */
final class OrderLookup {

	/**
	 * Longest order number accepted before the value is dismissed unread.
	 *
	 * @var int
	 */
	public const MAX_NUMBER_LENGTH = 64;

	/**
	 * The order a submitted pair identifies.
	 *
	 * @since 0.11.0
	 *
	 * @param string $order_number Submitted order number, already sanitised.
	 * @param string $email        Submitted billing email, already sanitised.
	 * @return \WC_Order|null Null whenever the pair does not identify exactly one order.
	 */
	public function find( string $order_number, string $email ): ?\WC_Order {
		$number = self::normalise_number( $order_number );

		if ( '' === $number || strlen( $number ) > self::MAX_NUMBER_LENGTH ) {
			return null;
		}

		$order_id = $this->resolve_id( $number );

		if ( $order_id < 1 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		// Not every id wc_get_order() accepts is an order: a refund's id resolves
		// to a different class entirely, and has no business being looked up.
		// The instanceof check is what keeps one out.
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( ! hash_equals( (string) $order->get_order_number(), $number ) ) {
			return null;
		}

		if ( ! self::email_matches( $order, $email ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Translates an order number into an order id.
	 *
	 * @since 0.11.0
	 *
	 * @param string $number Normalised order number.
	 * @return int Zero when nothing claims to know this number.
	 */
	private function resolve_id( string $number ): int {
		/**
		 * Filters the order id an order number resolves to.
		 *
		 * WooCommerce's own hook, documented here because this plugin fires it
		 * outside the shortcode it was introduced for. Custom-order-number
		 * plugins already answer it; on a store with none, the unfiltered value
		 * is the number itself, which casts to an id only when the number is
		 * numeric — which on such a store is exactly what an order number is.
		 *
		 * @since 0.11.0
		 *
		 * @param string $number Submitted order number.
		 */
		$resolved = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, deliberately reused so custom-order-number plugins need no knowledge of this one.

		/**
		 * Filters the order id this plugin's own lookup resolved a number to.
		 *
		 * For a store whose numbering plugin does not implement WooCommerce's
		 * hook above, or that wants lookup to resolve differently from core's
		 * tracking shortcode. Returning 0 makes the number unresolvable, which
		 * the visitor cannot tell apart from any other outcome.
		 *
		 * @since 0.11.0
		 *
		 * @param int    $order_id Resolved order id, 0 when unresolved.
		 * @param string $number   Submitted order number.
		 */
		$order_id = (int) apply_filters( 'wpmphub_lookup_order_id', is_scalar( $resolved ) ? (int) $resolved : 0, $number );

		return max( 0, $order_id );
	}

	/**
	 * Whether a submitted address is the order's billing address.
	 *
	 * Compared as two fixed-length hashes of the normalised addresses:
	 * `hash_equals()` on the raw strings would still leak their lengths
	 * through the comparison's own cost, and normalising first means a
	 * customer who types the same mailbox a different way still gets in.
	 *
	 * @since 0.11.0
	 *
	 * @param \WC_Order $order Order to compare against.
	 * @param string    $email Submitted address.
	 * @return bool
	 */
	private static function email_matches( \WC_Order $order, string $email ): bool {
		$stored = (string) $order->get_billing_email();

		if ( '' === $stored ) {
			return false;
		}

		return hash_equals( Sanitizer::hash_email( $stored ), Sanitizer::hash_email( $email ) );
	}

	/**
	 * Trims a submitted order number down to what a merchant would recognise.
	 *
	 * Customers copy the number out of an email with the `#` core prints in
	 * front of it, and core's own shortcode strips exactly that.
	 *
	 * @since 0.11.0
	 *
	 * @param string $order_number Raw submitted value.
	 * @return string
	 */
	private static function normalise_number( string $order_number ): string {
		return ltrim( trim( $order_number ), '#' );
	}
}
