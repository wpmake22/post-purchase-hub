<?php
/**
 * `.distignore` and the build's own exclusion list must agree.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two lists describe what never ships, and they had silently stopped matching.
 *
 * `bin/build.php` has always kept its own `dev_excludes()` array and has never
 * read `.distignore`. Nothing enforced the two staying in step, so `.distignore`
 * grew entries the build ignored — and the WP.org artifact shipped this plugin's
 * marketing screenshots, its PHPCS cache and its PHPUnit result cache to every
 * installation, the cache alone being several times the size of the plugin.
 *
 * `composer verify` did not catch it because it inspects the zip for a fixed
 * list of known-bad names rather than for anything `.distignore` claims to
 * exclude. This test closes that gap from the other end: it compares the two
 * declarations directly, so adding a path to one and forgetting the other fails
 * the suite instead of quietly inflating a release.
 *
 * The parity required is one-directional. Everything `.distignore` names must be
 * excluded by the build; the build may exclude more, because it also strips the
 * edition directories and other paths that are not development-only. One path is
 * deliberately absent from `.distignore` and asserted as such: the build ships a
 * rewritten, per-edition `composer.json` rather than dropping it.
 *
 * @since 0.15.0
 *
 * @coversNothing
 */
final class DistIgnoreParityTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The paths `.distignore` declares, normalised to match the build's own
	 * spelling: no leading slash, no comments, no blank lines.
	 *
	 * @return array<int, string>
	 */
	private function distignore_paths(): array {
		$file = $this->root() . '/.distignore';

		$this->assertFileExists( $file );

		$paths = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
		$declared = (string) file_get_contents( $file );

		foreach ( explode( "\n", $declared ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			$paths[] = ltrim( $line, '/' );
		}

		return $paths;
	}

	/**
	 * The paths `bin/build.php` excludes, read out of the source rather than by
	 * running it: the build is a CLI script that exits, and this only needs the
	 * declaration.
	 *
	 * @return array<int, string>
	 */
	private function build_source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
		return (string) file_get_contents( $this->root() . '/bin/build.php' );
	}

	/**
	 * The paths `bin/build.php` excludes.
	 *
	 * @return array<int, string>
	 */
	private function build_excludes(): array {
		$source = $this->build_source();

		$start = strpos( $source, 'function dev_excludes(): array {' );

		$this->assertNotFalse( $start, 'bin/build.php no longer declares dev_excludes().' );

		$end  = strpos( $source, '}', (int) $start );
		$body = substr( $source, (int) $start, (int) $end - (int) $start );

		preg_match_all( "/'([^']+)'/", $body, $matches );

		return $matches[1];
	}

	/**
	 * Every path `.distignore` promises to keep out of the build is one the
	 * build actually keeps out.
	 *
	 * @return void
	 */
	public function test_the_build_excludes_everything_distignore_declares(): void {
		$excludes = $this->build_excludes();
		$missing  = array();

		foreach ( $this->distignore_paths() as $path ) {
			if ( in_array( $path, $excludes, true ) ) {
				continue;
			}

			// A path is also covered when the build drops a directory above it:
			// `vendor/bin` needs no entry of its own once `vendor` is excluded.
			foreach ( $excludes as $exclude ) {
				if ( str_starts_with( $path, $exclude . '/' ) ) {
					continue 2;
				}
			}

			$missing[] = $path;
		}

		$this->assertSame(
			array(),
			$missing,
			"These are in .distignore but not in bin/build.php's dev_excludes(), so they ship: "
				. implode( ', ', $missing )
		);
	}

	/**
	 * `composer.json` stays out of `.distignore` on purpose.
	 *
	 * The build rewrites it per edition rather than dropping it, so a tool that
	 * honoured a `.distignore` naming it would produce an artifact missing a
	 * file this one deliberately ships.
	 *
	 * @return void
	 */
	public function test_composer_json_is_not_declared_as_excluded(): void {
		$this->assertNotContains(
			'composer.json',
			$this->distignore_paths(),
			'The build ships a rewritten composer.json; .distignore must not claim otherwise.'
		);
	}

	/**
	 * The build drops hidden files wherever they appear, not just at fixed
	 * paths.
	 *
	 * WordPress.org's Plugin Check treats any hidden file in the artifact as an
	 * error, and it found fourteen: a `.gitkeep` in every directory that had
	 * once been empty, and a `.DS_Store` in every directory macOS Finder had
	 * opened. Neither can be expressed as a path, because both turn up anywhere.
	 *
	 * @return void
	 */
	public function test_hidden_filenames_are_dropped_by_name(): void {
		$source = $this->build_source();

		$this->assertStringContainsString(
			'function hidden_filenames(): array',
			$source,
			'bin/build.php must declare the hidden-filename filter.'
		);

		$this->assertStringContainsString(
			'in_array( $current->getFilename(), hidden_filenames(), true )',
			$source,
			'The copier must consult hidden_filenames() as it walks the tree.'
		);

		foreach ( array( '.DS_Store', '.gitkeep' ) as $name ) {
			$this->assertStringContainsString(
				"'" . $name . "'",
				$source,
				$name . ' must be in the hidden-filename list.'
			);
		}
	}

	/**
	 * The exclusions this test was written for, named individually so a
	 * regression says which one came back.
	 *
	 * @return void
	 */
	public function test_the_known_offenders_stay_excluded(): void {
		$excludes = $this->build_excludes();

		foreach ( array( '.wordpress-org', '.phpcs.cache', '.phpunit.result.cache' ) as $path ) {
			$this->assertContains( $path, $excludes, $path . ' must never reach a release artifact.' );
		}
	}
}
