<?php
/**
 * Guard against the orders list regaining a query per row.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Frontend\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the orders-list render path loads no orders of its own.
 *
 * The list renders one row per order, and WooCommerce hands each row's hook the
 * order object it already read. Reading meta off that object is free. Calling
 * wc_get_order() instead is not: on post storage WC_Data_Store_WP::read_meta()
 * issues one query per order and WordPress's post-meta cache does not serve it,
 * so twenty rows become twenty queries.
 *
 * The integration suite measures this against a real database, which is the
 * real proof — but that suite needs Docker and does not run everywhere. This
 * one runs on every commit and names the mistake before it is made.
 *
 * @since 0.4.1
 */
final class NoPerRowQueriesTest extends TestCase {

	/**
	 * No method on the list path loads an order.
	 *
	 * @dataProvider list_path_methods
	 *
	 * @param string $method Method on Renderer that runs once per row.
	 * @return void
	 */
	public function test_the_list_path_loads_no_orders( string $method ): void {
		foreach ( array( 'wc_get_order(', 'wc_get_orders(', 'get_posts(', 'WP_Query' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$this->source_of( $method ),
				'Renderer::' . $method . '() runs once per row and must not ' . $needle
			);
		}
	}

	/**
	 * Methods the orders list calls for every row it draws.
	 *
	 * @return array<string, array{string}>
	 */
	public static function list_path_methods(): array {
		return array(
			'column'         => array( 'render_list_column' ),
			'timeline'       => array( 'render_timeline' ),
			'nested partial' => array( 'render_prepared_timeline' ),
			'orders list'    => array( 'render_orders_list' ),
		);
	}

	/**
	 * The detail page may load one order, because it draws exactly one.
	 *
	 * Asserted so the exception stays deliberate: if this ever stops being the
	 * only loader in the class, the test above is what catches it.
	 *
	 * @return void
	 */
	public function test_the_detail_path_is_the_only_loader(): void {
		$this->assertStringContainsString( 'wc_get_order(', $this->source_of( 'render_detail' ) );
	}

	/**
	 * The source of one method, located by reflection rather than by line number.
	 *
	 * @param string $method Method name.
	 * @return string
	 */
	private function source_of( string $method ): string {
		$reflected = new \ReflectionMethod( Renderer::class, $method );
		$file      = (string) $reflected->getFileName();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file from this repository, not a remote URL.
		$lines = explode( "\n", (string) file_get_contents( $file ) );

		return implode(
			"\n",
			array_slice(
				$lines,
				$reflected->getStartLine() - 1,
				$reflected->getEndLine() - $reflected->getStartLine() + 1
			)
		);
	}
}
