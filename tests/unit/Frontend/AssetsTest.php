<?php
/**
 * Asset scoping unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Frontend\Assets;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers where the stylesheet loads, and — the point of the class — everywhere
 * it must not.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\Assets
 */
final class AssetsTest extends TestCase {

	/**
	 * Assets under test.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Builds the loader over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->assets = new Assets();
	}

	/**
	 * Builds a fake queried post.
	 *
	 * @param string $content Post content.
	 * @return \WP_Post
	 */
	private function post( string $content ): \WP_Post {
		return new \WP_Post( $content );
	}

	/**
	 * A shop page, a product, the cart: nothing of ours loads.
	 *
	 * @return void
	 */
	public function test_nothing_loads_on_an_ordinary_page(): void {
		FakeWordPress::$post = $this->post( 'Just some content.' );

		$this->assertFalse( $this->assets->is_required() );
	}

	/**
	 * The My Account landing page is not an orders page.
	 *
	 * @return void
	 */
	public function test_nothing_loads_on_the_account_dashboard(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array();

		$this->assertFalse( $this->assets->is_required() );
	}

	/**
	 * Nor is the downloads endpoint.
	 *
	 * @return void
	 */
	public function test_nothing_loads_on_another_account_endpoint(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'downloads' );

		$this->assertFalse( $this->assets->is_required() );
	}

	/**
	 * The orders list and the order detail both load it.
	 *
	 * @dataProvider order_endpoints
	 *
	 * @param string $endpoint Endpoint name.
	 * @return void
	 */
	public function test_it_loads_on_order_endpoints( string $endpoint ): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( $endpoint );

		$this->assertTrue( $this->assets->is_required() );
	}

	/**
	 * The endpoints that show orders.
	 *
	 * @return array<string, array{string}>
	 */
	public static function order_endpoints(): array {
		return array(
			'orders'     => array( 'orders' ),
			'view-order' => array( 'view-order' ),
		);
	}

	/**
	 * An endpoint outside My Account does not count.
	 *
	 * @return void
	 */
	public function test_an_endpoint_outside_my_account_does_not_count(): void {
		FakeWordPress::$is_account_page = false;
		FakeWordPress::$endpoints       = array( 'orders' );

		$this->assertFalse( $this->assets->is_required() );
	}

	/**
	 * A page embedding the shortcode loads it.
	 *
	 * @return void
	 */
	public function test_it_loads_on_a_page_with_the_shortcode(): void {
		FakeWordPress::$post = $this->post( 'Before [wpmphub_orders limit="5"] after.' );

		$this->assertTrue( $this->assets->is_required() );
	}

	/**
	 * A page embedding the block loads it.
	 *
	 * @return void
	 */
	public function test_it_loads_on_a_page_with_the_block(): void {
		FakeWordPress::$post = $this->post( '<!-- wp:wpmphub/orders /-->' );

		$this->assertTrue( $this->assets->is_required() );
	}

	/**
	 * A surface we cannot detect can announce itself.
	 *
	 * @return void
	 */
	public function test_the_filter_can_force_loading(): void {
		add_filter(
			'wpmphub_enqueue_assets',
			static function (): bool {
				return true;
			}
		);

		$this->assertTrue( $this->assets->is_required() );
	}

	/**
	 * And a merchant can switch it off where we would have loaded.
	 *
	 * @return void
	 */
	public function test_the_filter_can_prevent_loading(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'orders' );

		add_filter(
			'wpmphub_enqueue_assets',
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $this->assets->is_required() );
	}

	/**
	 * On a required page, both the stylesheet and the request-modal script enqueue.
	 *
	 * @return void
	 */
	public function test_enqueue_loads_the_style_and_the_script_where_required(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'orders' );

		$this->assets->enqueue();

		$this->assertArrayHasKey( Assets::STYLE_HANDLE, FakeWordPress::$enqueued_styles );
		$this->assertArrayHasKey( Assets::SCRIPT_HANDLE, FakeWordPress::$enqueued_scripts );
	}

	/**
	 * The script is localised with a REST URL and a nonce.
	 *
	 * @return void
	 */
	public function test_enqueue_localises_the_rest_url_and_nonce(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'orders' );

		$this->assets->enqueue();

		$data = FakeWordPress::$localized_scripts[ Assets::SCRIPT_HANDLE ]['wpmphubRequests'];

		$this->assertStringContainsString( 'wpmphub/v1/requests', $data['restUrl'] );
		$this->assertNotSame( '', $data['nonce'] );
	}

	/**
	 * The reorder script stays off an order page that is not showing a
	 * reconciliation summary — there is no form on it to submit.
	 *
	 * @return void
	 */
	public function test_the_reorder_script_stays_off_an_ordinary_order_page(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'view-order' );

		$this->assets->enqueue();

		$this->assertArrayNotHasKey( Assets::REORDER_HANDLE, FakeWordPress::$enqueued_scripts );
	}

	/**
	 * On the summary render it enqueues, localised with the reorder route.
	 *
	 * @return void
	 */
	public function test_the_reorder_script_loads_on_the_summary_render(): void {
		FakeWordPress::$is_account_page = true;
		FakeWordPress::$endpoints       = array( 'view-order' );
		$_GET[ Reorder::QUERY_ARG ]     = '42';

		$this->assets->enqueue();

		$this->assertArrayHasKey( Assets::REORDER_HANDLE, FakeWordPress::$enqueued_scripts );

		$data = FakeWordPress::$localized_scripts[ Assets::REORDER_HANDLE ]['wpmphubReorder'];

		$this->assertStringContainsString( 'wpmphub/v1/reorder', $data['restUrl'] );
		$this->assertNotSame( '', $data['nonce'] );

		unset( $_GET[ Reorder::QUERY_ARG ] );
	}

	/**
	 * Nothing enqueues where the assets are not required.
	 *
	 * @return void
	 */
	public function test_enqueue_loads_nothing_where_not_required(): void {
		FakeWordPress::$post = $this->post( 'Just some content.' );

		$this->assets->enqueue();

		$this->assertSame( array(), FakeWordPress::$enqueued_styles );
		$this->assertSame( array(), FakeWordPress::$enqueued_scripts );
	}
}
