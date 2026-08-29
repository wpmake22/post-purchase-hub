<?php
/**
 * Rendering integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Frontend\Assets;
use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Plugin;

/**
 * Exercises the rendering layer against real orders and a real theme.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\Renderer
 * @covers \PostPurchaseHub\Frontend\TemplateLoader
 * @covers \PostPurchaseHub\Frontend\Assets
 */
final class RenderingTest extends \WP_UnitTestCase {

	/**
	 * Every test in this class is about a configured store.
	 *
	 * Since M14 this plugin renders nothing on the storefront until the setup
	 * wizard has been completed (`Install\SetupState`), so the premise has to
	 * be stated rather than assumed. The one test that is about the opposite
	 * says so itself.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SetupState::complete();
	}

	/**
	 * Nothing this plugin draws reaches a customer before the wizard is done —
	 * and, in particular, an embedded shortcode renders as nothing rather than
	 * printing its own raw text at them.
	 *
	 * @return void
	 */
	public function test_nothing_renders_before_setup_completes(): void {
		delete_option( SetupState::OPTION );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );

		$order = new \WC_Order();
		$order->set_customer_id( $customer );
		$order->set_status( 'processing' );
		$order->save();

		wp_set_current_user( $customer );

		$output = do_shortcode( '[wpmphub_orders]' );

		$this->assertSame( '', $output );
		$this->assertStringNotContainsString( '[wpmphub_orders]', $output );
	}


	/**
	 * Creates orders in the state a live store's list would show.
	 *
	 * @param int $count How many orders to create.
	 * @return array<int, \WC_Order>
	 */
	private function orders( int $count ): array {
		$orders = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$order = new \WC_Order();
			$order->set_status( 'pending' );
			$order->save();

			$order->set_status( 'processing' );
			$order->save();

			$orders[] = $order;
		}

		return $orders;
	}

	/**
	 * Rendering the orders list adds no queries per row.
	 *
	 * The single hardest requirement in this milestone. WooCommerce hands the
	 * column hook the order object it already loaded, so the timeline comes out
	 * of meta that is already in memory; calling wc_get_order() in the loop
	 * instead would cost a query per row on post storage, where meta is read one
	 * order at a time.
	 *
	 * @return void
	 */
	public function test_the_orders_list_adds_no_queries_per_row(): void {
		$orders   = $this->orders( 20 );
		$renderer = Plugin::instance()->renderer();

		// Warm anything the first render would lazily populate — options, the
		// template path, the stage map — so the measurement is per row and not
		// per first-use.
		ob_start();
		$renderer->render_list_column( $orders[0] );
		ob_get_clean();

		$before = get_num_queries();

		ob_start();

		foreach ( $orders as $order ) {
			$renderer->render_list_column( $order );
		}

		$markup = (string) ob_get_clean();

		$this->assertSame( 0, get_num_queries() - $before, 'Rendering 20 rows must add no queries.' );
		$this->assertSame( 20, substr_count( $markup, 'data-wpmphub-timeline-summary' ) );
	}

	/**
	 * The detail timeline renders once, however many times its hook fires.
	 *
	 * @return void
	 */
	public function test_the_detail_timeline_renders_once_per_order(): void {
		$order    = $this->orders( 1 )[0];
		$renderer = Plugin::instance()->renderer();

		ob_start();

		$renderer->render_detail( $order->get_id() );
		$renderer->render_detail( $order->get_id() );

		$markup = (string) ob_get_clean();

		// Counted on the stage list, not on `data-wpmphub-timeline=`. The section
		// carries `data-wpmphub-timeline` as a bare boolean attribute, so the
		// version with an equals sign appears nowhere in the markup and this
		// assertion could only ever have read zero — including when the
		// timeline rendered perfectly, which is what it was doing.
		$this->assertSame( 1, substr_count( $markup, 'data-wpmphub-timeline-stages' ) );
	}

	/**
	 * The rendered timeline is accessible markup, not a colour-coded div.
	 *
	 * @return void
	 */
	public function test_the_timeline_markup_is_accessible(): void {
		$order = $this->orders( 1 )[0];

		ob_start();
		Plugin::instance()->renderer()->render_detail( $order->get_id() );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( '<ol', $markup );
		$this->assertStringContainsString( 'aria-labelledby', $markup );
		$this->assertStringContainsString( 'data-wpmphub-stage-state=', $markup );

		// Every stage states its condition in words as well as in styling.
		$this->assertSame(
			substr_count( $markup, 'data-wpmphub-stage=' ),
			substr_count( $markup, 'data-wpmphub-stage-state-label' )
		);
	}

	/**
	 * Output is escaped even when a filter injects markup into a stage label.
	 *
	 * @return void
	 */
	public function test_stage_labels_are_escaped(): void {
		$order = $this->orders( 1 )[0];

		add_filter(
			'wpmphub_timeline_stages',
			static function (): array {
				return array( 'placed' => '<script>alert(1)</script>' );
			}
		);

		// A fresh container, because the stage map memoises the filtered list.
		$plugin = new Plugin();

		ob_start();
		$plugin->renderer()->render_detail( $order->get_id() );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
	}

	/**
	 * The progress column is added next to status and disturbs nothing.
	 *
	 * @return void
	 */
	public function test_the_column_is_added_to_woocommerces_own_list(): void {
		Plugin::instance()->renderer()->register();

		$columns = wc_get_account_orders_columns();

		$this->assertArrayHasKey( Renderer::LIST_COLUMN, $columns );

		foreach ( array( 'order-number', 'order-date', 'order-status', 'order-total', 'order-actions' ) as $core ) {
			$this->assertArrayHasKey( $core, $columns, $core . ' must survive' );
		}
	}

	/**
	 * Assets stay off a page that renders none of this plugin's markup.
	 *
	 * @return void
	 */
	public function test_no_assets_on_an_unrelated_page(): void {
		$this->go_to( home_url( '/' ) );

		$assets = new Assets();
		$assets->enqueue();

		$this->assertFalse( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Assets load on a page embedding the shortcode.
	 *
	 * @return void
	 */
	public function test_assets_load_on_a_page_with_the_shortcode(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[wpmphub_orders]',
			)
		);

		$this->go_to( (string) get_permalink( $page_id ) );

		$assets = new Assets();
		$assets->enqueue();

		$this->assertTrue( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Replacement mode is off until a merchant asks for it.
	 *
	 * @return void
	 */
	public function test_replacement_is_off_by_default(): void {
		$replacer = Plugin::instance()->template_replacer();

		$this->assertFalse( $replacer->is_enabled() );
		$this->assertFalse( $replacer->is_requested() );
	}

	/**
	 * Asked for on a clean theme, WooCommerce renders this plugin's template.
	 *
	 * @return void
	 */
	public function test_replacement_swaps_woocommerces_template(): void {
		update_option( 'wpmphub_settings', array( TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT ) );

		$plugin = new Plugin();
		$plugin->template_replacer()->register();

		$located = apply_filters( 'wc_get_template', '/core/orders.php', 'myaccount/orders.php', array(), '', '' );

		$this->assertSame( WPMPHUB_PLUGIN_DIR . 'templates/myaccount/orders.php', $located );
	}

	/**
	 * WooCommerce templates this plugin does not own are never swapped.
	 *
	 * @return void
	 */
	public function test_replacement_leaves_other_templates_alone(): void {
		update_option( 'wpmphub_settings', array( TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT ) );

		$plugin = new Plugin();
		$plugin->template_replacer()->register();

		$this->assertSame(
			'/core/cart.php',
			apply_filters( 'wc_get_template', '/core/cart.php', 'cart/cart.php', array(), '', '' )
		);
	}

	/**
	 * Replacement mode still shows the merchant's notes to the customer.
	 *
	 * WooCommerce's own view-order template lists these as "Order updates".
	 * Replacing that template must not quietly take them away.
	 *
	 * @return void
	 */
	public function test_replacement_mode_keeps_the_customer_notes(): void {
		$order = $this->orders( 1 )[0];
		$order->add_order_note( 'Your parcel leaves the warehouse tonight.', 1 );

		ob_start();
		Plugin::instance()->renderer()->render_order_notes( wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-wpmphub-order-notes', $markup );
		$this->assertStringContainsString( 'Your parcel leaves the warehouse tonight.', $markup );
	}

	/**
	 * An internal note is never shown to the customer.
	 *
	 * @return void
	 */
	public function test_private_notes_are_never_rendered(): void {
		$order = $this->orders( 1 )[0];
		$order->add_order_note( 'Chargeback risk, watch this one.' );

		ob_start();
		Plugin::instance()->renderer()->render_order_notes( wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Chargeback risk', $markup );
		$this->assertSame( '', $markup );
	}

	/**
	 * Note content is escaped, not echoed.
	 *
	 * @return void
	 */
	public function test_note_content_is_escaped(): void {
		$order = $this->orders( 1 )[0];
		$order->add_order_note( 'Shipped <script>alert(1)</script>', 1 );

		ob_start();
		Plugin::instance()->renderer()->render_order_notes( wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $markup );
	}

	/**
	 * The shortcode shows a guest nothing belonging to anyone else.
	 *
	 * @return void
	 */
	public function test_the_shortcode_shows_a_guest_no_orders(): void {
		$this->orders( 2 );

		wp_set_current_user( 0 );

		$output = do_shortcode( '[wpmphub_orders]' );

		$this->assertStringContainsString( 'data-wpmphub-orders-empty', $output );
		$this->assertStringNotContainsString( 'data-wpmphub-timeline', $output );
	}

	/**
	 * The shortcode shows a customer their own orders and no one else's.
	 *
	 * @return void
	 */
	public function test_the_shortcode_shows_only_the_viewers_orders(): void {
		$mine     = self::factory()->user->create( array( 'role' => 'customer' ) );
		$stranger = self::factory()->user->create( array( 'role' => 'customer' ) );

		$my_order = new \WC_Order();
		$my_order->set_customer_id( $mine );
		$my_order->set_status( 'processing' );
		$my_order->save();

		$their_order = new \WC_Order();
		$their_order->set_customer_id( $stranger );
		$their_order->set_status( 'processing' );
		$their_order->save();

		wp_set_current_user( $mine );

		Plugin::instance()->renderer()->register();

		$output = do_shortcode( '[wpmphub_orders]' );

		$this->assertStringContainsString( 'data-wpmphub-order-id="' . $my_order->get_id() . '"', $output );
		$this->assertStringNotContainsString( 'data-wpmphub-order-id="' . $their_order->get_id() . '"', $output );
	}
}
