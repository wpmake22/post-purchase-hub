<?php
/**
 * Release builder — produces the free and pro distribution zips from one source tree.
 *
 * Usage:
 *   php bin/build.php --version=1.2.0
 *   php bin/build.php --version=1.2.0 --edition=free
 *   php bin/build.php --edition=pro --out=dist
 *
 * Both zips contain a folder named `post-purchase-hub/` so the Pro build upgrades
 * the free plugin in place rather than installing alongside it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

const SLUG      = 'post-purchase-hub';
const MAIN_FILE = 'post-purchase-hub.php';

$root = dirname( __DIR__ );
$args = parse_args( $argv );

$edition_arg = $args['edition'] ?? 'both';
$out_dir     = $root . '/' . ( $args['out'] ?? 'dist' );
$version     = $args['version'] ?? read_version( $root . '/' . MAIN_FILE );

if ( ! preg_match( '/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/i', $version ) ) {
	fail( "Invalid version '{$version}'. Expected semver, e.g. 1.2.0" );
}

$editions = 'both' === $edition_arg ? array( 'free', 'pro' ) : array( $edition_arg );

foreach ( $editions as $edition ) {
	if ( ! in_array( $edition, array( 'free', 'pro' ), true ) ) {
		fail( "Unknown edition '{$edition}'. Use free, pro, or both." );
	}
}

info( "Building version {$version}" );

preflight( $root, $editions );

$built = array();

foreach ( $editions as $edition ) {
	$built[ $edition ] = build_edition( $root, $out_dir, $edition, $version );
}

info( 'Done.' );

foreach ( $built as $edition => $zip ) {
	printf( "  %-4s  %s  (%s)\n", $edition, basename( $zip ), human_bytes( (int) filesize( $zip ) ) );
}

// Machine-readable output for CI.
file_put_contents(
	$out_dir . '/build-manifest.json',
	(string) json_encode(
		array(
			'version'   => $version,
			'slug'      => SLUG,
			'built_at'  => gmdate( 'c' ),
			'artifacts' => array_map( 'basename', $built ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

exit( 0 );


/* -------------------------------------------------------------------------
 * Build
 * ---------------------------------------------------------------------- */

/**
 * Build a single edition and return the zip path.
 *
 * @param string $root    Repository root.
 * @param string $out_dir Output directory.
 * @param string $edition free|pro.
 * @param string $version Semver version.
 * @return string
 */
function build_edition( string $root, string $out_dir, string $edition, string $version ): string {
	info( "── {$edition} ──" );

	$staging = $out_dir . '/staging/' . $edition;
	$target  = $staging . '/' . SLUG;

	rrmdir( $staging );
	mkdirp( $target );

	// 1. Copy source, excluding dev files and the other edition's directory.
	$excluded = array_merge( dev_excludes(), edition_excludes( $edition ) );
	$copied   = copy_tree( $root, $target, $excluded );
	info( "   copied {$copied} files" );

	// 2. Rewrite the main plugin file header and edition constant.
	rewrite_main_file( $target . '/' . MAIN_FILE, $edition, $version );

	// 3. Edition-specific file handling.
	if ( 'free' === $edition ) {
		rewrite_readme_stable_tag( $target . '/readme.txt', $version );
		@unlink( $target . '/README.md' );
	} else {
		// readme.txt is a WP.org artifact; Pro ships README.md instead.
		@unlink( $target . '/readme.txt' );
	}

	// 4. Trim the autoload map so the stripped edition namespace is not registered,
	//    then install runtime dependencies inside staging.
	rewrite_composer_autoload( $target . '/composer.json', $edition );
	run(
		sprintf(
			'composer install --no-dev --no-interaction --quiet --optimize-autoloader --classmap-authoritative --working-dir=%s',
			escapeshellarg( $target )
		)
	);
	@unlink( $target . '/composer.lock' );

	// 5. Syntax-check everything that ships.
	$lint_errors = lint_tree( $target );
	if ( $lint_errors ) {
		fail( "PHP syntax errors in {$edition} build:\n" . implode( "\n", $lint_errors ) );
	}

	// 6. Zip.
	$suffix = 'pro' === $edition ? '-pro' : '';
	$zip    = sprintf( '%s/%s%s-%s.zip', $out_dir, SLUG, $suffix, $version );
	@unlink( $zip );
	zip_dir( $staging, $zip );

	rrmdir( $staging );

	return $zip;
}

/**
 * Fail early on the mistakes that produce a broken zip.
 *
 * @param string $root     Repository root.
 * @param array  $editions Editions being built.
 */
function preflight( string $root, array $editions ): void {
	if ( ! is_file( $root . '/' . MAIN_FILE ) ) {
		fail( 'Main plugin file not found.' );
	}

	if ( ! is_dir( $root . '/assets/build' ) ) {
		fail( 'assets/build is missing. Run `npm run build` before building a release.' );
	}

	if ( in_array( 'pro', $editions, true ) ) {
		if ( ! is_dir( $root . '/pro' ) ) {
			fail( 'pro/ directory is missing; cannot build the pro edition.' );
		}
		if ( is_dir( $root . '/pro/assets/src' ) && ! is_dir( $root . '/pro/assets/build' ) ) {
			fail( 'pro/assets/build is missing. Run `npm run build` before building a release.' );
		}
	}

	// The leakage guard: core must never reference an edition namespace.
	$leaks = grep_tree(
		$root . '/src',
		'/PostPurchaseHub\\\\(Pro|Free)\\\\/',
		array()
	);
	if ( $leaks ) {
		fail(
			"Core references an edition namespace. Core must depend only on extension points.\n"
			. implode( "\n", array_map( static fn( $l ) => '  ' . $l, $leaks ) )
		);
	}
}


/* -------------------------------------------------------------------------
 * File rewriting
 * ---------------------------------------------------------------------- */

/**
 * Rewrite the plugin header and edition constant.
 *
 * `Update URI` matters: the Pro build shares its slug with a WP.org-hosted plugin,
 * so without this WordPress will happily "update" a paying customer's Pro install
 * down to the free version.
 *
 * @param string $file    Main plugin file path.
 * @param string $edition free|pro.
 * @param string $version Semver version.
 */
function rewrite_main_file( string $file, string $edition, string $version ): void {
	$src = (string) file_get_contents( $file );

	$src = preg_replace( '/^(\s*\*\s*Version:\s*).+$/m', '${1}' . $version, $src, 1 );

	$src = preg_replace(
		"/define\(\s*'PPH_EDITION'\s*,\s*'[^']*'\s*\)/",
		"define( 'PPH_EDITION', '{$edition}' )",
		$src,
		1,
		$count
	);

	if ( ! $count ) {
		fail( "PPH_EDITION constant not found in " . MAIN_FILE . '. The build cannot mark the edition.' );
	}

	if ( 'pro' === $edition ) {
		$src = preg_replace(
			'/^(\s*\*\s*Plugin Name:\s*.+)$/m',
			'${1} (Pro)',
			$src,
			1
		);

		if ( ! preg_match( '/^\s*\*\s*Update URI:/m', $src ) ) {
			$src = preg_replace(
				'/^(\s*\*\s*Version:\s*.+)$/m',
				"\${1}\n * Update URI:        https://example.com/post-purchase-hub-pro",
				$src,
				1
			);
		}
	} else {
		// Free is hosted on WP.org; strip any Update URI so core handles updates.
		$src = preg_replace( '/^\s*\*\s*Update URI:.*\R/m', '', $src );
	}

	file_put_contents( $file, $src );
}

/**
 * Keep readme.txt's Stable tag in step with the release.
 *
 * @param string $file    readme.txt path.
 * @param string $version Semver version.
 */
function rewrite_readme_stable_tag( string $file, string $version ): void {
	if ( ! is_file( $file ) ) {
		fail( 'readme.txt is required for the free build.' );
	}

	$src = (string) file_get_contents( $file );
	$src = preg_replace( '/^(Stable tag:\s*).+$/m', '${1}' . $version, $src, 1, $count );

	if ( ! $count ) {
		fail( 'readme.txt has no Stable tag line.' );
	}

	file_put_contents( $file, $src );
}

/**
 * Remove the stripped edition's PSR-4 mapping so the optimized autoloader is clean.
 *
 * @param string $file    composer.json path in staging.
 * @param string $edition free|pro.
 */
function rewrite_composer_autoload( string $file, string $edition ): void {
	$json = json_decode( (string) file_get_contents( $file ), true );

	if ( ! is_array( $json ) ) {
		fail( 'Could not parse composer.json.' );
	}

	$drop = 'free' === $edition ? 'PostPurchaseHub\\Pro\\' : 'PostPurchaseHub\\Free\\';
	unset( $json['autoload']['psr-4'][ $drop ] );
	unset( $json['autoload-dev'], $json['require-dev'], $json['scripts'] );

	file_put_contents(
		$file,
		(string) json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
	);
}


/* -------------------------------------------------------------------------
 * Exclusion lists
 * ---------------------------------------------------------------------- */

/**
 * Paths never shipped in any edition, relative to the repository root.
 *
 * @return string[]
 */
function dev_excludes(): array {
	return array(
		'.git',
		'.github',
		'.claude',
		'.gitignore',
		'.editorconfig',
		'.distignore',
		// WP.org serves banners, icons and screenshots from the `assets/`
		// directory of its own SVN repository, never from the plugin zip. They
		// were shipping to every installation as dead weight.
		'.wordpress-org',
		'.wp-env.json',
		'.wp-env.hpos.json',
		'.wp-env.override.json',
		'.wp-env.ci.json',
		// Tooling caches. `.phpcs.cache` alone was the largest single file in
		// the artifact, several times the size of the plugin.
		'.phpcs.cache',
		'.phpunit.result.cache',
		'node_modules',
		'vendor',
		'dist',
		'bin',
		'docs',
		'tests',
		'pro/tests',
		'free/tests',
		'assets/src',
		'pro/assets/src',
		'free/assets/src',
		'phpcs.xml.dist',
		'phpstan.neon.dist',
		'phpunit.xml',
		'phpunit.xml.dist',
		'phpunit-integration.xml.dist',
		'package.json',
		'package-lock.json',
		'webpack.config.js',
		'CLAUDE.md',
		'CHANGELOG.md',
		'SECURITY.md',
		'composer.lock',
	);
}

/**
 * Paths excluded for a specific edition.
 *
 * @param string $edition free|pro.
 * @return string[]
 */
function edition_excludes( string $edition ): array {
	return 'free' === $edition ? array( 'pro' ) : array( 'free' );
}


/* -------------------------------------------------------------------------
 * Filesystem helpers
 * ---------------------------------------------------------------------- */

/**
 * Recursively copy, skipping excluded paths. Returns the file count.
 *
 * @param string   $from     Source directory.
 * @param string   $to       Destination directory.
 * @param string[] $excludes Relative paths to skip.
 * @return int
 */
function copy_tree( string $from, string $to, array $excludes ): int {
	$count    = 0;
	$excludes = array_flip( $excludes );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
			static function ( $current ) use ( $from, $excludes ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $current->getPathname(), strlen( $from ) ) ), '/' );

				if ( isset( $excludes[ $rel ] ) ) {
					return false;
				}

				foreach ( array_keys( $excludes ) as $ex ) {
					if ( str_starts_with( $rel, $ex . '/' ) ) {
						return false;
					}
				}

				return true;
			}
		),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$rel  = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $from ) ) ), '/' );
		$dest = $to . '/' . $rel;

		if ( $item->isDir() ) {
			mkdirp( $dest );
			continue;
		}

		mkdirp( dirname( $dest ) );
		copy( $item->getPathname(), $dest );
		++$count;
	}

	return $count;
}

/**
 * php -l every PHP file in the tree. Returns error lines.
 *
 * @param string $dir Directory to lint.
 * @return string[]
 */
function lint_tree( string $dir ): array {
	$errors = array();

	foreach ( php_files( $dir ) as $file ) {
		if ( str_contains( $file, '/vendor/' ) ) {
			continue;
		}

		exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );

		if ( 0 !== $code ) {
			$errors[] = implode( ' ', $out );
		}

		$out = array();
	}

	return $errors;
}

/**
 * Grep PHP files for a pattern. Returns "file:line" matches.
 *
 * @param string   $dir      Directory to search.
 * @param string   $pattern  PCRE pattern.
 * @param string[] $excludes Substrings to skip.
 * @return string[]
 */
function grep_tree( string $dir, string $pattern, array $excludes = array() ): array {
	$hits = array();

	if ( ! is_dir( $dir ) ) {
		return $hits;
	}

	foreach ( php_files( $dir ) as $file ) {
		foreach ( $excludes as $ex ) {
			if ( str_contains( $file, $ex ) ) {
				continue 2;
			}
		}

		foreach ( (array) file( $file ) as $n => $line ) {
			if ( preg_match( $pattern, (string) $line ) ) {
				$hits[] = $file . ':' . ( $n + 1 );
			}
		}
	}

	return $hits;
}

/**
 * All PHP files under a directory.
 *
 * @param string $dir Directory.
 * @return string[]
 */
function php_files( string $dir ): array {
	$files = array();

	if ( ! is_dir( $dir ) ) {
		return $files;
	}

	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}

	sort( $files );

	return $files;
}

/**
 * Zip a directory, sorted for reproducible output.
 *
 * @param string $dir  Directory whose contents become the archive root.
 * @param string $zip  Output zip path.
 */
function zip_dir( string $dir, string $zip ): void {
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fail( "Could not create {$zip}" );
	}

	$entries = array();
	$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$path            = str_replace( '\\', '/', $file->getPathname() );
			$entries[ $path ] = ltrim( substr( $path, strlen( $dir ) ), '/' );
		}
	}

	asort( $entries );

	foreach ( $entries as $path => $local ) {
		$archive->addFile( $path, $local );
	}

	$archive->close();
}

/**
 * Recursive mkdir.
 *
 * @param string $dir Directory.
 */
function mkdirp( string $dir ): void {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		fail( "Could not create {$dir}" );
	}
}

/**
 * Recursive delete.
 *
 * @param string $dir Directory.
 */
function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}

	rmdir( $dir );
}


/* -------------------------------------------------------------------------
 * Misc
 * ---------------------------------------------------------------------- */

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
 * Read the Version header from the main plugin file.
 *
 * @param string $file Main plugin file.
 * @return string
 */
function read_version( string $file ): string {
	if ( ! is_file( $file ) ) {
		fail( 'Main plugin file not found.' );
	}

	preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', (string) file_get_contents( $file ), $m );

	return trim( $m[1] ?? '' );
}

/**
 * Run a shell command, failing the build on non-zero exit.
 *
 * @param string $cmd Command.
 */
function run( string $cmd ): void {
	exec( $cmd . ' 2>&1', $out, $code );

	if ( 0 !== $code ) {
		fail( "Command failed: {$cmd}\n" . implode( "\n", $out ) );
	}
}

/**
 * Human-readable byte size.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function human_bytes( int $bytes ): string {
	return $bytes > 1048576
		? round( $bytes / 1048576, 1 ) . ' MB'
		: round( $bytes / 1024 ) . ' KB';
}

/**
 * Print an informational line.
 *
 * @param string $msg Message.
 */
function info( string $msg ): void {
	fwrite( STDOUT, $msg . "\n" );
}

/**
 * Abort the build.
 *
 * @param string $msg Message.
 */
function fail( string $msg ): void {
	fwrite( STDERR, "\n BUILD FAILED\n " . str_replace( "\n", "\n ", $msg ) . "\n\n" );
	exit( 1 );
}
