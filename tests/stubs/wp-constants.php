<?php
/**
 * Constants that static analysis cannot discover on its own.
 *
 * The php-stubs/wordpress-stubs package declares functions and classes but no
 * constants, so
 * PHPStan reports every core constant as undefined. This file is listed under
 * `scanFiles` in phpstan.neon.dist: PHPStan reads the definitions without
 * executing them, and nothing at runtime ever loads it. It lives under tests/
 * so the release build, which drops that directory, cannot ship it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- These deliberately mirror WordPress core constant names.

/*
 * Deliberately not a literal: with a constant path, PHPStan resolves
 * `ABSPATH . 'wp-admin/...'` against this machine's filesystem and reports the
 * WordPress files it cannot find.
 */
define( 'ABSPATH', (string) getenv( 'WPMPHUB_STUB_ABSPATH' ) );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

/*
 * This plugin's own path constants. wpmake-post-purchase-hub.php is analysed, but
 * PHPStan only registers a define() whose value is a constant expression, and
 * these are computed from plugin_dir_path() and plugin_dir_url(). Values are
 * irrelevant — only the fact that the constants exist, and their type.
 */
define( 'WPMPHUB_PLUGIN_DIR', (string) getenv( 'WPMPHUB_STUB_PLUGIN_DIR' ) );
define( 'WPMPHUB_PLUGIN_URL', (string) getenv( 'WPMPHUB_STUB_PLUGIN_URL' ) );
define( 'WPMPHUB_PLUGIN_FILE', (string) getenv( 'WPMPHUB_STUB_PLUGIN_FILE' ) );
