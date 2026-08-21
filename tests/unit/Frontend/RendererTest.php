<?php
/**
 * Renderer unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers how the orders table is extended, which is the part that has to leave
 * every theme's existing columns exactly as they were.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\Renderer
 */
final class RendererTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Builds the renderer over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$stages = new StageMap( new StatusDetector( new Cache() ) );

		$this->renderer = new Renderer(
			new TimelineBuilder( $stages, new TransitionRecorder( $stages, new Logger() ) ),
			new TemplateLoader( new Logger() )
		);
	}

	/**
	 * WooCommerce's own columns, in their default order.
	 *
	 * @return array<string, string>
	 */
	private function core_columns(): array {
		return array(
			'order-number'  => 'Order',
			'order-date'    => 'Date',
			'order-status'  => 'Status',
			'order-total'   => 'Total',
			'order-actions' => 'Actions',
		);
	}

	/**
	 * The column lands directly after the status it explains.
	 *
	 * @return void
	 */
	public function test_the_column_is_inserted_after_status(): void {
		$columns = $this->renderer->add_list_column( $this->core_columns() );

		$this->assertSame(
			array( 'order-number', 'order-date', 'order-status', Renderer::LIST_COLUMN, 'order-total', 'order-actions' ),
			array_keys( $columns )
		);
	}

	/**
	 * Nothing WooCommerce or a theme already put there is disturbed.
	 *
	 * @return void
	 */
	public function test_existing_columns_are_left_untouched(): void {
		$columns                = $this->core_columns();
		$columns['theme-extra'] = 'Extra';

		$result = $this->renderer->add_list_column( $columns );

		foreach ( $columns as $id => $label ) {
			$this->assertSame( $label, $result[ $id ], $id . ' must keep its label' );
		}
	}

	/**
	 * A theme that removed the status column still gets the progress column.
	 *
	 * @return void
	 */
	public function test_the_column_is_appended_when_status_is_absent(): void {
		$columns = $this->renderer->add_list_column( array( 'order-number' => 'Order' ) );

		$this->assertSame( array( 'order-number', Renderer::LIST_COLUMN ), array_keys( $columns ) );
	}

	/**
	 * A filter returning something absurd cannot blank the table.
	 *
	 * @return void
	 */
	public function test_non_array_columns_yield_an_array(): void {
		$this->assertSame( array(), $this->renderer->add_list_column( 'nonsense' ) );
	}

	/**
	 * Merchants can decline the extra column.
	 *
	 * @return void
	 */
	public function test_the_column_can_be_switched_off(): void {
		add_filter(
			'pph_orders_list_column',
			static function (): bool {
				return false;
			}
		);

		$this->renderer->register();

		$this->assertArrayNotHasKey( 'woocommerce_account_orders_columns', FakeWordPress::$filters );
	}

	/**
	 * By default the column is wired up.
	 *
	 * @return void
	 */
	public function test_the_column_is_wired_by_default(): void {
		$this->renderer->register();

		$this->assertArrayHasKey( 'woocommerce_account_orders_columns', FakeWordPress::$filters );
		$this->assertArrayHasKey(
			'woocommerce_my_account_my_orders_column_' . Renderer::LIST_COLUMN,
			FakeWordPress::$actions
		);
	}

	/**
	 * Only one detail hook is taken, so the timeline cannot render twice.
	 *
	 * Core attaches its own order-details table to `woocommerce_view_order`, and
	 * that table fires `woocommerce_order_details_after_order_table` from inside
	 * it. Hooking both would draw the timeline once per hook.
	 *
	 * @return void
	 */
	public function test_only_one_detail_hook_is_taken(): void {
		$this->renderer->register();

		$this->assertArrayHasKey( 'woocommerce_view_order', FakeWordPress::$actions );
		$this->assertArrayNotHasKey( 'woocommerce_order_details_after_order_table', FakeWordPress::$actions );
	}

	/**
	 * A row that is not an order renders nothing rather than failing.
	 *
	 * @return void
	 */
	public function test_a_non_order_row_renders_nothing(): void {
		ob_start();

		$this->renderer->render_list_column( null );
		$this->renderer->render_list_column( 'not an order' );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
