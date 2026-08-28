<?php
/**
 * The plugin header, checked against the things that reject a release.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two release mistakes that cost a round trip each, caught here instead.
 *
 * WordPress.org refused an upload because `Plugin URI` and `Author URI` held
 * the same value. Neither header is required, but if both are present they have
 * to describe different things — one a page about this plugin, the other a page
 * about whoever wrote it. Nothing in the build looks at either, so nothing
 * caught it until the directory did.
 *
 * The version assertions come from the other near-miss. The version is written
 * in four places, and `bin/build.php` rewrites only two of them when it stages
 * an edition — so bumping the header without the constant produced an artifact
 * that told WordPress one number and reported another to its own cache busting.
 *
 * Both are checked against the source rather than a built zip, so they fail
 * during development rather than at upload.
 *
 * @since 1.0.0
 *
 * @coversNothing
 */
final class PluginHeaderTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The main plugin file's contents.
	 *
	 * @return string
	 */
	private function main_file(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
		return (string) file_get_contents( $this->root() . '/wpmake-post-purchase-hub.php' );
	}

	/**
	 * One header's value, or an empty string when the header is absent.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private function header( string $name ): string {
		$pattern = '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+)$/m';

		return preg_match( $pattern, $this->main_file(), $matches ) ? trim( $matches[1] ) : '';
	}

	/**
	 * If both URI headers are present they must differ, because the directory
	 * refuses the upload when they do not.
	 *
	 * @return void
	 */
	public function test_the_plugin_and_author_uris_are_not_the_same(): void {
		$plugin = $this->header( 'Plugin URI' );
		$author = $this->header( 'Author URI' );

		if ( '' === $plugin || '' === $author ) {
			$this->assertTrue( true, 'Only one URI header is present, which the directory allows.' );

			return;
		}

		$this->assertNotSame(
			$plugin,
			$author,
			'WordPress.org rejects an upload whose Plugin URI and Author URI are identical.'
		);
	}

	/**
	 * The version is written in four places and has to agree in all of them.
	 *
	 * @return void
	 */
	public function test_every_declared_version_agrees(): void {
		$header = $this->header( 'Version' );

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/i',
			$header,
			'The Version header must be semver, or bin/build.php refuses to build.'
		);

		preg_match( "/define\(\s*'PPH_VERSION'\s*,\s*'([^']+)'\s*\)/", $this->main_file(), $constant );

		$this->assertSame(
			$header,
			$constant[1] ?? '',
			'PPH_VERSION must match the Version header. The build rewrites the header but never the constant.'
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
		$readme = (string) file_get_contents( $this->root() . '/readme.txt' );

		preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $stable );

		$this->assertSame(
			$header,
			trim( $stable[1] ?? '' ),
			"The readme's stable tag must match the Version header."
		);
	}

	/**
	 * The text domain has to be the plugin slug, or translations from the
	 * directory never load.
	 *
	 * @return void
	 */
	public function test_the_text_domain_is_the_slug(): void {
		$this->assertSame( 'wpmake-post-purchase-hub', $this->header( 'Text Domain' ) );
	}

	/**
	 * WooCommerce is a hard dependency and has to be declared as one, so
	 * WordPress refuses activation rather than fataling on a missing class.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_declared_as_required(): void {
		$this->assertSame( 'woocommerce', $this->header( 'Requires Plugins' ) );
	}
}
