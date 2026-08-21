<?php
/**
 * Guard against order notes becoming a data source.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the timeline reads no order notes.
 *
 * Order notes are translated when they are written and merchants edit and
 * delete them, so a timeline derived from them breaks on any store that is not
 * in English and on any store whose staff tidy up. The rule is easy to forget
 * once someone is deep in a "but we could recover the shipped date" refactor,
 * so it is asserted against the source rather than left to review.
 *
 * @since 0.3.0
 */
final class NoOrderNotesTest extends TestCase {

	/**
	 * The timeline source contains no order-note reads.
	 *
	 * @dataProvider timeline_sources
	 *
	 * @param string $path Absolute path to a source file.
	 * @return void
	 */
	public function test_no_order_note_api_is_used( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file from this repository, not a remote URL.
		$source = (string) file_get_contents( $path );

		foreach ( array( 'wc_get_order_notes', 'get_customer_order_notes', 'comment_type', 'order_note' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$source,
				basename( $path ) . ' must not read order notes.'
			);
		}
	}

	/**
	 * Every source file that builds or records a timeline.
	 *
	 * @return array<string, array{string}>
	 */
	public static function timeline_sources(): array {
		$root  = dirname( __DIR__, 3 );
		$paths = glob( $root . '/src/Timeline/*.php' );
		$paths = is_array( $paths ) ? $paths : array();

		$paths[] = $root . '/src/CLI/BackfillCommand.php';

		$cases = array();

		foreach ( $paths as $path ) {
			$cases[ basename( $path ) ] = array( $path );
		}

		return $cases;
	}
}
