<?php
/**
 * Uninstall handler.
 *
 * WordPress loads this file when the plugin is deleted, in a request where the
 * plugin itself is not running. It therefore boots nothing beyond the autoloader
 * and hands over to Install\Uninstaller, which is autoloaded, statically
 * analysed and covered by tests — none of which is true of code that lives here.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pph_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $pph_autoload ) ) {
	// Without the autoloader there is nothing to run; leaving data is the safe outcome.
	return;
}

require_once $pph_autoload;

PostPurchaseHub\Install\Uninstaller::run();
