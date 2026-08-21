<?php
/**
 * Edition boundary unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts core knows nothing about the editions built on top of it.
 *
 * CI greps for the same things, but a developer finds out here in a second
 * rather than in a pipeline in ten minutes — and the free artifact is a strict
 * subset of this source, so a leak found late is a leak that shipped.
 *
 * @since 0.5.0
 */
final class EditionBoundaryTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every core PHP file, plus the main plugin file.
	 *
	 * @return array<int, string>
	 */
	private function core_files(): array {
		$files = array( $this->root() . '/post-purchase-hub.php', $this->root() . '/uninstall.php' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function contents( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file from this repository, not a remote URL.
		return (string) file_get_contents( $path );
	}

	/**
	 * No core file names an edition namespace, in code or in a string.
	 *
	 * @return void
	 */
	public function test_core_never_names_an_edition_namespace(): void {
		foreach ( $this->core_files() as $path ) {
			$this->assertDoesNotMatchRegularExpression(
				'/PostPurchaseHub\\\\+(Pro|Free)\\\\+/',
				$this->contents( $path ),
				basename( $path ) . ' names an edition namespace. Core registers extension points; editions fill them.'
			);
		}
	}

	/**
	 * No file under src/ asks which edition it is running in.
	 *
	 * @return void
	 */
	public function test_core_never_branches_on_edition(): void {
		foreach ( $this->core_files() as $path ) {
			if ( $this->root() . '/post-purchase-hub.php' === $path ) {
				// The helper is declared there; the rule is about src/ using it.
				continue;
			}

			$this->assertStringNotContainsString(
				'pph_is_pro',
				$this->contents( $path ),
				basename( $path ) . ' branches on edition. If Pro cannot be built on core\'s public surface, the surface is wrong.'
			);
		}

		$this->assertStringNotContainsString( 'PPH_EDITION', $this->contents( $this->root() . '/src/Plugin.php' ) );
	}

	/**
	 * Nothing anywhere uses an inline build marker.
	 *
	 * @return void
	 */
	public function test_no_inline_build_markers(): void {
		foreach ( $this->core_files() as $path ) {
			$this->assertDoesNotMatchRegularExpression(
				'/#if__(PREMIUM|FREE)/',
				$this->contents( $path ),
				basename( $path ) . ' carries a build marker. Editions are separated by directory, never by marker.'
			);
		}
	}

	/**
	 * The regexes above can actually catch what they are looking for.
	 *
	 * A gate that matches nothing passes forever. This asserts the patterns
	 * against both forms a leak really takes.
	 *
	 * @dataProvider leaks
	 *
	 * @param string $leak Source text that must be caught.
	 * @return void
	 */
	public function test_the_boundary_patterns_catch_a_real_leak( string $leak ): void {
		$this->assertMatchesRegularExpression( '/PostPurchaseHub\\\\+(Pro|Free)\\\\+/', $leak );
	}

	/**
	 * The two shapes an edition reference takes in PHP source.
	 *
	 * @return array<string, array{string}>
	 */
	public static function leaks(): array {
		return array(
			'use statement'  => array( 'use PostPurchaseHub\Pro\Bootstrap;' ),
			'escaped string' => array( "\$x = 'PostPurchaseHub\\\\Free\\\\Bootstrap';" ),
			'class_exists'   => array( "class_exists( 'PostPurchaseHub\\\\Pro\\\\Bootstrap' )" ),
			'docblock'       => array( ' * @param \PostPurchaseHub\Free\Bootstrap $b Bootstrap.' ),
		);
	}
}
