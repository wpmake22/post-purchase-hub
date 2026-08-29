<?php
/**
 * Reading an email's settings without loading the email.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

/**
 * Answers "is that email switched on?" without touching a `WC_Email` subclass.
 *
 * This class exists because of a real fatal. `AbstractEmail extends \WC_Email`,
 * and WooCommerce includes `class-wc-email.php` only inside
 * `WC_Emails::init_emails()` — reached through `WC()->mailer()`, and nowhere
 * else. So *any* code that names one of this plugin's email classes before
 * something has called that is a white screen: the autoloader loads a class
 * whose parent does not exist yet.
 *
 * The rule that follows is simple and worth stating once: nothing outside
 * `src/Emails/` may name a class that extends `AbstractEmail`, and nothing
 * inside it may either until WooCommerce's mailer is up. What callers actually
 * need is never the object — it is one option value, which is what this class
 * hands them. `Tests\Unit\Emails\EmailBootOrderTest` enforces the rule.
 *
 * The option name is WooCommerce's own convention
 * (`WC_Settings_API::get_option_key()`): `woocommerce_{$email_id}_settings`.
 *
 * @since 0.14.1
 */
final class EmailSettings {

	/**
	 * Id of the help-request email.
	 *
	 * Duplicated from `HelpRequest::ID` deliberately, because reading it from
	 * there would load the class this file exists to avoid loading. A test
	 * asserts the two never drift apart.
	 *
	 * @var string
	 */
	public const HELP_REQUEST = 'wpmphub_help_request';

	/**
	 * Whether one of this plugin's emails will send.
	 *
	 * An unset option means enabled, matching the `enabled` field's own default
	 * in `AbstractEmail::enabled_field()`: a store that has never visited
	 * WooCommerce → Settings → Emails still gets its emails.
	 *
	 * @since 0.14.1
	 *
	 * @param string $email_id The email's `WC_Email::$id`.
	 * @return bool
	 */
	public static function is_enabled( string $email_id ): bool {
		$settings = get_option( self::option_name( $email_id ), array() );

		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) ) {
			return true;
		}

		return 'no' !== $settings['enabled'];
	}

	/**
	 * The option one email's settings are stored under.
	 *
	 * @since 0.14.1
	 *
	 * @param string $email_id The email's `WC_Email::$id`.
	 * @return string
	 */
	public static function option_name( string $email_id ): string {
		return 'woocommerce_' . $email_id . '_settings';
	}
}
