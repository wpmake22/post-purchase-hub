<?php
/**
 * Input sanitisation and output-safety helpers for the security layer.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * Pure sanitisation functions, plus the one nocache() side effect Phase 8 asks
 * every order-bearing response to carry.
 *
 * All static, like `Support\Dates` — every method here is a function of its
 * arguments with no service to inject, so it is not carried in the container.
 * This class holds no domain vocabulary of its own: `reason_code()` validates
 * against whatever whitelist the caller (the feature that actually defines
 * reason codes) supplies, rather than this layer inventing one ahead of it.
 *
 * @since 0.6.0
 */
final class Sanitizer {

	/**
	 * Cap applied by note(), matching the `customer_note` / `admin_note` columns.
	 *
	 * @var int
	 */
	public const NOTE_MAX_LENGTH = 2000;

	/**
	 * Lower-cases an email and folds dots and `+` tags out of its local part.
	 *
	 * Not full RFC validation — this exists so two spellings of the same
	 * mailbox hash identically for rate limiting, not to judge whether the
	 * address is well-formed. Only the local part is touched: a dot is
	 * structural in the domain and folding it there would merge unrelated
	 * hosts.
	 *
	 * `+` tags fold for the same reason dots do, and it is not cosmetic: an
	 * attacker who gets a fresh per-address budget for every
	 * `mailbox+1@example.com` spelling has no per-email rate limit at all
	 * (docs/MILESTONE-PROMPTS.md M11's email-alias bypass test). Over-folding
	 * a provider that treats `+` literally costs that mailbox a shared
	 * counter and nothing else — link emails go to the address stored on the
	 * order either way, never to a submitted one.
	 *
	 * @since 0.6.0
	 *
	 * @param string $email Candidate email address.
	 * @return string
	 */
	public static function normalise_email( string $email ): string {
		$email = strtolower( trim( $email ) );

		$at = strrpos( $email, '@' );

		if ( false === $at ) {
			return self::fold_local( $email );
		}

		return self::fold_local( substr( $email, 0, $at ) ) . substr( $email, $at );
	}

	/**
	 * Folds the alias spellings out of an email's local part.
	 *
	 * @since 0.11.0
	 *
	 * @param string $local Local part, already lower-cased.
	 * @return string
	 */
	private static function fold_local( string $local ): string {
		$plus = strpos( $local, '+' );

		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}

		return str_replace( '.', '', $local );
	}

	/**
	 * SHA-256 of a normalised email, for rate limiting and storage without a
	 * second copy of the address itself.
	 *
	 * @since 0.6.0
	 *
	 * @param string $email Candidate email address.
	 * @return string
	 */
	public static function hash_email( string $email ): string {
		return hash( 'sha256', self::normalise_email( $email ) );
	}

	/**
	 * Validates a reason code against a caller-supplied whitelist.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed    $value     Candidate code.
	 * @param string[] $whitelist Accepted codes.
	 * @return string|null Null when the value is not in the whitelist.
	 */
	public static function reason_code( $value, array $whitelist ): ?string {
		$code = is_scalar( $value ) ? (string) $value : '';

		return in_array( $code, $whitelist, true ) ? $code : null;
	}

	/**
	 * Strips markup and caps the length of a customer- or merchant-written note.
	 *
	 * Stripping happens on write, on the assumption that every later reader
	 * still escapes at output — this is a defence-in-depth cap on stored
	 * content, not a substitute for esc_html()/esc_html__() at render time.
	 *
	 * @since 0.6.0
	 *
	 * @param string $note       Raw note.
	 * @param int    $max_length Maximum length after stripping. Defaults to the column cap.
	 * @return string
	 */
	public static function note( string $note, int $max_length = self::NOTE_MAX_LENGTH ): string {
		$stripped = wp_strip_all_tags( $note );

		return mb_substr( trim( $stripped ), 0, $max_length );
	}

	/**
	 * Marks the current response as never cacheable.
	 *
	 * `DONOTCACHEPAGE` is the constant page-cache plugins (WP Rocket,
	 * LiteSpeed and others) check before ever writing a page to disk; the
	 * header covers everything else, including reverse proxies and CDNs that
	 * do not.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public static function nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Fixed name that cache plugins check for; not ours to prefix.
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store' );
		}
	}
}
