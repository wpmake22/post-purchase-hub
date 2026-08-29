<?php
/**
 * Runs this plugin's early code paths with no WooCommerce at all.
 *
 * Executed as its own PHP process by `Tests\Unit\Emails\EmailBootOrderTest`,
 * because the fatal it guards against can only happen where `WC_Email` is
 * undefined — and the unit suite defines a stub for it. WooCommerce includes
 * `class-wc-email.php` inside its own mailer's boot and nowhere else, so on
 * `plugins_loaded`, on the activation hook, and on any storefront render that
 * has not sent mail, the class genuinely does not exist.
 *
 * Prints `OK` and exits 0 when every path ran without loading an email class.
 * Anything else — a fatal, or `WC_Email` having been pulled in — is the
 * regression.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The shims and constants must carry the WordPress names they stand in for.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput -- A CLI harness printing its own literals to stdout, not shipped code and not HTML.

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['wpmphub_scheduled'] = array();

/**
 * Reads an option: always the default, since this harness has no database.
 *
 * @param string $option        Option name.
 * @param mixed  $default_value Default.
 * @return mixed
 */
function get_option( $option, $default_value = false ) {
	unset( $option );

	return $default_value;
}

/**
 * Accepts an option write and discards it.
 *
 * @param string $option   Option name.
 * @param mixed  $value    Value.
 * @param bool   $autoload Whether to autoload.
 * @return bool
 */
function update_option( $option, $value, $autoload = null ): bool {
	unset( $option, $value, $autoload );

	return true;
}

/**
 * Returns the value unfiltered: no hooks exist in this harness.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Value to filter.
 * @param mixed  ...$args   Extra arguments.
 * @return mixed
 */
function apply_filters( $hook_name, $value, ...$args ) {
	unset( $hook_name, $args );

	return $value;
}

/**
 * Swallows an action.
 *
 * @param string $hook_name Hook name.
 * @param mixed  ...$args   Arguments.
 * @return void
 */
function do_action( $hook_name, ...$args ): void {
	unset( $hook_name, $args );
}

/**
 * Reports whether this harness has scheduled an event.
 *
 * @param string $hook Hook name.
 * @return int|false
 */
function wp_next_scheduled( $hook ) {
	return $GLOBALS['wpmphub_scheduled'][ $hook ] ?? false;
}

/**
 * Records a scheduled event.
 *
 * @param int    $timestamp  When to run.
 * @param string $recurrence Interval.
 * @param string $hook       Hook name.
 * @return bool
 */
function wp_schedule_event( $timestamp, $recurrence, $hook ): bool {
	unset( $recurrence );

	$GLOBALS['wpmphub_scheduled'][ $hook ] = (int) $timestamp;

	return true;
}

/**
 * Forgets a scheduled event.
 *
 * @param string $hook Hook name.
 * @return int|false
 */
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['wpmphub_scheduled'][ $hook ] );

	return 1;
}

/**
 * Returns the string unchanged; this harness loads no translations.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
	unset( $domain );

	return $text;
}

/**
 * Returns the string unchanged, ignoring its translator context.
 *
 * @param string $text    Text.
 * @param string $context Context.
 * @param string $domain  Text domain.
 * @return string
 */
function _x( $text, $context, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- A shim, not a translation call.
	unset( $context, $domain );

	return $text;
}

/**
 * Reports no persistent object cache.
 *
 * @param bool|null $using Unused.
 * @return bool
 */
function wp_using_ext_object_cache( $using = null ): bool {
	unset( $using );

	return false;
}

/**
 * Accepts a transient deletion.
 *
 * @param string $name Transient name.
 * @return bool
 */
function delete_transient( $name ): bool {
	unset( $name );

	return true;
}

// The three paths that ran before WooCommerce's mailer and used to reach an
// email class: the activation scheduler, the deactivation sweep, and the help
// action deciding whether to draw its form on an order page.
PostPurchaseHub\Install\Activator::schedule_digest();

if ( ! isset( $GLOBALS['wpmphub_scheduled'][ PostPurchaseHub\Install\Activator::DIGEST_HOOK ] ) ) {
	echo "FAIL: the digest event was not scheduled\n";
	exit( 1 );
}

// Deactivation's cron list, read the way PHP reads it: evaluating the constant
// expression is what used to autoload an email class. The rest of
// Deactivator::deactivate() talks to the database and is not what this harness
// is about.
$wpmphub_hooks = ( new ReflectionClass( PostPurchaseHub\Install\Deactivator::class ) )->getConstant( 'CRON_HOOKS' );

if ( ! is_array( $wpmphub_hooks ) || ! in_array( PostPurchaseHub\Install\Activator::DIGEST_HOOK, $wpmphub_hooks, true ) ) {
	echo "FAIL: deactivation would not clear the digest event\n";
	exit( 1 );
}

PostPurchaseHub\Actions\Help::has_destination();

if ( class_exists( 'WC_Email', false ) ) {
	echo "FAIL: an email class was loaded before WooCommerce's mailer\n";
	exit( 1 );
}

foreach ( array( 'AdminDigest', 'HelpRequest', 'AbstractEmail' ) as $wpmphub_email_class ) {
	if ( class_exists( 'PostPurchaseHub\\Emails\\' . $wpmphub_email_class, false ) ) {
		echo 'FAIL: ' . $wpmphub_email_class . " was autoloaded on an early path\n";
		exit( 1 );
	}
}

echo "OK\n";
