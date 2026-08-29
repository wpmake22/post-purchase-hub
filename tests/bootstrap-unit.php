<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * Deliberately loads no WordPress, no WooCommerce and no database, so unit
 * tests stay fast enough to run on save.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

$wpmphub_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $wpmphub_autoload ) ) {
	echo "Composer autoloader missing. Run: composer install\n";
	exit( 1 );
}

require_once $wpmphub_autoload;
