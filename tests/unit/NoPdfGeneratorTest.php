<?php
/**
 * The no-PDF-generation gate.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts this plugin has not grown a PDF engine.
 *
 * Milestone 13 states the rule and its acceptance criterion in the same breath
 * (docs/MILESTONE-PROMPTS.md): "NO PDF GENERATION. No dompdf, mPDF, TCPDF or any PDF
 * library may appear in composer.json or the codebase" and "grep confirms no
 * PDF library anywhere". This is that grep, run on save rather than by hand,
 * because the reason for the rule outlives the milestone: docs/SPEC.md Phase 3
 * rejects a generator on ~4MB of vendor code and jurisdiction-specific
 * invoicing law, and a dependency added quietly two milestones from now would
 * cost both without anyone deciding to.
 *
 * Deliberately in the unit suite and not only in CI: a developer finds out in a
 * second here (this repository's Actions minutes are a real constraint), and
 * the check is worth nothing if it only runs after a push.
 *
 * @since 0.13.0
 */
final class NoPdfGeneratorTest extends TestCase {

	/**
	 * Package and library names that would mean a generator had arrived.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN = array(
		'dompdf',
		'mpdf',
		'tcpdf',
		'fpdf',
		'wkhtmltopdf',
		'html2pdf',
		'phpwkhtmltopdf',
		'setasign/fpdi',
		'pdflib',
		'puppeteer',
		'pdfkit',
		'jspdf',
		'pdf-lib',
	);

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function contents( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a manifest from this repository, not a remote URL.
		return strtolower( (string) file_get_contents( $path ) );
	}

	/**
	 * Every shipped source file, plus the manifests that could pull a library in.
	 *
	 * @return array<int, string>
	 */
	private function files(): array {
		$files = array();

		foreach ( array( 'composer.json', 'composer.lock', 'package.json', 'wpmake-post-purchase-hub.php' ) as $manifest ) {
			$path = $this->root() . '/' . $manifest;

			if ( is_readable( $path ) ) {
				$files[] = $path;
			}
		}

		foreach ( array( 'src', 'free/src', 'pro/src', 'templates', 'assets/src' ) as $directory ) {
			$path = $this->root() . '/' . $directory;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && in_array( $file->getExtension(), array( 'php', 'js', 'json', 'scss' ), true ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * No PDF library is named anywhere this plugin ships from.
	 *
	 * @return void
	 */
	public function test_no_pdf_library_is_present(): void {
		$offenders = array();

		foreach ( $this->files() as $path ) {
			$contents = $this->contents( $path );

			foreach ( self::FORBIDDEN as $library ) {
				if ( str_contains( $contents, $library ) ) {
					$offenders[] = basename( $path ) . ' names ' . $library;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"This plugin generates no PDFs (docs/SPEC.md Phase 3). It reads what an invoice plugin already produced:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The runtime dependency list stays empty, which is the stronger form of
	 * the same rule (CLAUDE.md hard rule 11).
	 *
	 * @return void
	 */
	public function test_no_runtime_composer_dependency_was_added(): void {
		$manifest = json_decode( $this->contents( $this->root() . '/composer.json' ), true );

		$this->assertIsArray( $manifest );
		$this->assertSame(
			array( 'php' ),
			array_keys( $manifest['require'] ?? array() ),
			'The free plugin ships no runtime vendor packages.'
		);
	}
}
