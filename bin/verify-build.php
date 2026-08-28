<?php
/**
 * Release verifier — inspects the built zips, not the source tree.
 *
 * The build script can be correct and still ship the wrong thing. This checks the
 * actual artifacts a user would download, which is the only thing that matters.
 *
 * Usage:
 *   php bin/verify-build.php --version=1.2.0
 *   php bin/verify-build.php --version=1.2.0 --out=dist
 *
 * Exits non-zero on any failure. Wire it into CI immediately after the build.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

const SLUG      = 'wpmake-post-purchase-hub';
const MAIN_FILE = 'wpmake-post-purchase-hub.php';

$root    = dirname( __DIR__ );
$args    = parse_args( $argv );
$out_dir = $root . '/' . ( $args['out'] ?? 'dist' );
$version = $args['version'] ?? null;

if ( ! $version ) {
	$manifest = $out_dir . '/build-manifest.json';

	if ( ! is_file( $manifest ) ) {
		fail( 'No --version given and no build-manifest.json found.' );
	}

	$version = (string) ( json_decode( (string) file_get_contents( $manifest ), true )['version'] ?? '' );
}

$failures = array();
$checks   = 0;

/* ---------------------------------------------------------------------------
 * Free edition
 * ------------------------------------------------------------------------ */

$free_zip = sprintf( '%s/%s-%s.zip', $out_dir, SLUG, $version );

if ( is_file( $free_zip ) ) {
	section( 'free', basename( $free_zip ) );

	$files = zip_entries( $free_zip );

	// Structure.
	check( 'single root folder named ' . SLUG, single_root( $files ) === SLUG . '/' );
	check( 'contains main plugin file', in_array( SLUG . '/' . MAIN_FILE, $files, true ) );
	check( 'contains readme.txt', in_array( SLUG . '/readme.txt', $files, true ) );
	check( 'contains built assets', has_prefix( $files, SLUG . '/assets/build/' ) );
	check( 'contains vendor autoloader', in_array( SLUG . '/vendor/autoload.php', $files, true ) );

	// The whole point of the exercise: no pro code in the free zip.
	check( 'no pro/ directory', ! has_prefix( $files, SLUG . '/pro/' ) );
	check( 'no Pro namespace anywhere', ! zip_grep( $free_zip, '/PostPurchaseHub\\\\Pro\\\\/' ) );
	check( 'no Pro namespace in autoload map', ! zip_grep( $free_zip, '/PostPurchaseHub\\\\\\\\Pro/', 'vendor/composer/' ) );
	check( 'no licensing code', ! zip_grep( $free_zip, '/(license_key|licence_key|edd_action|freemius|\bfs_dynamic_init\b)/i' ) );

	// Spec hard rule 7 — the free plugin makes no outbound requests.
	check( 'no outbound HTTP', ! zip_grep( $free_zip, '/\b(wp_remote_(get|post|request|head)|curl_init|file_get_contents\s*\(\s*[\'"]https?:)/' ) );

	// Spec hard rules 3 and 8.
	check( 'no open REST permissions', ! zip_grep( $free_zip, '/permission_callback[\'"\s]*=>\s*[\'"]?__return_true/' ) );
	check( 'never issues refunds', ! zip_grep( $free_zip, '/wc_create_refund|WC_Order_Refund/' ) );
	check( 'never creates a session', ! zip_grep( $free_zip, '/wp_set_auth_cookie/' ) );

	// Packaging hygiene.
	check( 'no dev directories', ! has_any_prefix( $files, array( SLUG . '/tests/', SLUG . '/node_modules/', SLUG . '/.git', SLUG . '/bin/', SLUG . '/docs/' ) ) );
	// Inverted deliberately. This check used to assert the opposite,
	// on the reasoning that sources are dead weight in an installed plugin. The
	// first WP.org review of 1.0.0 rejected that: guideline 4 requires public
	// access to the source of every compiled asset and to the build tools, and
	// every bundle in assets/build was flagged. The sources ship now, and the
	// check guards the requirement rather than the old habit.
	check( 'contains source assets', has_prefix( $files, SLUG . '/assets/src/' ) );
	check( 'contains build tooling', in_array( SLUG . '/package.json', $files, true ) && in_array( SLUG . '/webpack.config.js', $files, true ) );
	check( 'no dev config', ! has_any_suffix( $files, array( 'phpcs.xml.dist', 'phpstan.neon.dist', 'composer.lock', 'CLAUDE.md' ) ) );

	// Headers.
	$main = read_zip_entry( $free_zip, SLUG . '/' . MAIN_FILE );
	check( "Version header is {$version}", header_value( $main, 'Version' ) === $version );
	check( 'edition constant is free', (bool) preg_match( "/define\(\s*'PPH_EDITION'\s*,\s*'free'\s*\)/", $main ) );
	check( 'no Update URI header', ! preg_match( '/^\s*\*\s*Update URI:/m', $main ) );

	$readme = read_zip_entry( $free_zip, SLUG . '/readme.txt' );
	check( "Stable tag is {$version}", trim( (string) ( preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $m ) ? $m[1] : '' ) ) === $version );
	check( 'readme declares limitations', stripos( $readme, 'does not' ) !== false );
	check( 'readme documents the build', stripos( $readme, 'npm run build' ) !== false );
} else {
	$failures[] = 'free zip not found: ' . basename( $free_zip );
}

/* ---------------------------------------------------------------------------
 * Pro edition
 * ------------------------------------------------------------------------ */

$pro_zip = sprintf( '%s/%s-pro-%s.zip', $out_dir, SLUG, $version );

if ( is_file( $pro_zip ) ) {
	section( 'pro', basename( $pro_zip ) );

	$files = zip_entries( $pro_zip );

	// Folder name must match the free slug or Pro installs alongside instead of upgrading.
	check( 'root folder matches free slug', single_root( $files ) === SLUG . '/' );
	check( 'contains pro/ directory', has_prefix( $files, SLUG . '/pro/' ) );
	check( 'contains core src/', has_prefix( $files, SLUG . '/src/' ) );
	check( 'no free/ upsell directory', ! has_prefix( $files, SLUG . '/free/' ) );
	check( 'no readme.txt', ! in_array( SLUG . '/readme.txt', $files, true ) );

	$main = read_zip_entry( $pro_zip, SLUG . '/' . MAIN_FILE );
	check( "Version header is {$version}", header_value( $main, 'Version' ) === $version );
	check( 'edition constant is pro', (bool) preg_match( "/define\(\s*'PPH_EDITION'\s*,\s*'pro'\s*\)/", $main ) );

	// Without Update URI, WP.org will overwrite a paying customer's Pro install
	// with the free version on the next update check.
	check( 'has Update URI header', (bool) preg_match( '/^\s*\*\s*Update URI:\s*\S+/m', $main ) );

	check( 'no dev directories', ! has_any_prefix( $files, array( SLUG . '/tests/', SLUG . '/pro/tests/', SLUG . '/node_modules/', SLUG . '/.git' ) ) );
} else {
	$failures[] = 'pro zip not found: ' . basename( $pro_zip );
}

/* ---------------------------------------------------------------------------
 * Result
 * ------------------------------------------------------------------------ */

echo "\n";

if ( $failures ) {
	fwrite( STDERR, sprintf( " VERIFICATION FAILED — %d of %d checks\n\n", count( $failures ), $checks ) );

	foreach ( $failures as $f ) {
		fwrite( STDERR, '   ✗ ' . $f . "\n" );
	}

	fwrite( STDERR, "\n" );
	exit( 1 );
}

printf( " All %d checks passed.\n\n", $checks );
exit( 0 );


/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Record and print a check result.
 *
 * @param string $label     Description.
 * @param bool   $condition Result.
 */
function check( string $label, bool $condition ): void {
	global $failures, $checks, $current_edition;

	++$checks;

	if ( $condition ) {
		printf( "   ✓ %s\n", $label );
		return;
	}

	printf( "   ✗ %s\n", $label );
	$failures[] = "[{$current_edition}] {$label}";
}

/**
 * Print an edition header.
 *
 * @param string $edition Edition.
 * @param string $file    Zip filename.
 */
function section( string $edition, string $file ): void {
	global $current_edition;

	$current_edition = $edition;

	printf( "\n %s — %s\n", strtoupper( $edition ), $file );
}

/**
 * List all entry names in a zip.
 *
 * @param string $zip Zip path.
 * @return string[]
 */
function zip_entries( string $zip ): array {
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		fail( "Could not open {$zip}" );
	}

	$entries = array();

	for ( $i = 0; $i < $archive->numFiles; $i++ ) {
		$entries[] = (string) $archive->getNameIndex( $i );
	}

	$archive->close();

	return $entries;
}

/**
 * Read one file out of a zip.
 *
 * @param string $zip   Zip path.
 * @param string $entry Entry name.
 * @return string
 */
function read_zip_entry( string $zip, string $entry ): string {
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		return '';
	}

	$contents = (string) $archive->getFromName( $entry );
	$archive->close();

	return $contents;
}

/**
 * Search every PHP file in a zip for a pattern.
 *
 * @param string $zip          Zip path.
 * @param string $pattern      PCRE pattern.
 * @param string $only_within  Optional path substring to restrict the search to.
 * @return bool
 */
function zip_grep( string $zip, string $pattern, string $only_within = '' ): bool {
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		return false;
	}

	for ( $i = 0; $i < $archive->numFiles; $i++ ) {
		$name = (string) $archive->getNameIndex( $i );

		if ( '' !== $only_within && ! str_contains( $name, $only_within ) ) {
			continue;
		}

		if ( '' === $only_within && ! str_ends_with( $name, '.php' ) ) {
			continue;
		}

		if ( preg_match( $pattern, (string) $archive->getFromIndex( $i ) ) ) {
			$archive->close();
			return true;
		}
	}

	$archive->close();

	return false;
}

/**
 * The single top-level folder in the archive, or empty if there is not exactly one.
 *
 * @param string[] $files Entry names.
 * @return string
 */
function single_root( array $files ): string {
	$roots = array();

	foreach ( $files as $f ) {
		$roots[ strstr( $f, '/', false ) ? substr( $f, 0, strpos( $f, '/' ) + 1 ) : $f ] = true;
	}

	return 1 === count( $roots ) ? (string) array_key_first( $roots ) : '';
}

/**
 * Whether any entry starts with the prefix.
 *
 * @param string[] $files  Entry names.
 * @param string   $prefix Prefix.
 * @return bool
 */
function has_prefix( array $files, string $prefix ): bool {
	foreach ( $files as $f ) {
		if ( str_starts_with( $f, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether any entry starts with any of the prefixes.
 *
 * @param string[] $files    Entry names.
 * @param string[] $prefixes Prefixes.
 * @return bool
 */
function has_any_prefix( array $files, array $prefixes ): bool {
	foreach ( $prefixes as $p ) {
		if ( has_prefix( $files, $p ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether any entry ends with any of the suffixes.
 *
 * @param string[] $files    Entry names.
 * @param string[] $suffixes Suffixes.
 * @return bool
 */
function has_any_suffix( array $files, array $suffixes ): bool {
	foreach ( $files as $f ) {
		foreach ( $suffixes as $s ) {
			if ( str_ends_with( $f, $s ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Extract a plugin header value.
 *
 * @param string $src    File contents.
 * @param string $header Header name.
 * @return string
 */
function header_value( string $src, string $header ): string {
	preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/m', $src, $m );

	return trim( $m[1] ?? '' );
}

/**
 * Parse --key=value arguments.
 *
 * @param array $argv Raw argv.
 * @return array<string,string>
 */
function parse_args( array $argv ): array {
	$args = array();

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( preg_match( '/^--([a-z-]+)(?:=(.*))?$/', $arg, $m ) ) {
			$args[ $m[1] ] = $m[2] ?? '1';
		}
	}

	return $args;
}

/**
 * Abort.
 *
 * @param string $msg Message.
 */
function fail( string $msg ): void {
	fwrite( STDERR, "\n {$msg}\n\n" );
	exit( 1 );
}
