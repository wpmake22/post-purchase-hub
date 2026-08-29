<?php
/**
 * Replacement mode unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the gate: replacement happens only when it was asked for and only when
 * it would not throw a theme's own layout away.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\TemplateReplacer
 * @covers \PostPurchaseHub\Admin\TemplateConflictScanner
 */
final class TemplateReplacerTest extends TestCase {

	/**
	 * Clears the fake WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Builds a replacer, optionally with replacement mode requested.
	 *
	 * @param bool $replacement Whether the setting asks for replacement.
	 * @return TemplateReplacer
	 */
	private function replacer( bool $replacement ): TemplateReplacer {
		if ( $replacement ) {
			FakeWordPress::$options['wpmphub_settings'] = array(
				TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT,
			);
		}

		return new TemplateReplacer(
			new TemplateLoader( new Logger() ),
			new TemplateConflictScanner( new Cache() )
		);
	}

	/**
	 * Marks the theme as owning one of the watched templates.
	 *
	 * @return void
	 */
	private function add_theme_conflict(): void {
		FakeWordPress::$theme_templates = array( 'woocommerce/myaccount/orders.php' => __FILE__ );
	}

	/**
	 * With no setting at all, WooCommerce's own template is returned untouched.
	 *
	 * @return void
	 */
	public function test_replacement_is_off_by_default(): void {
		$replacer = $this->replacer( false );

		$this->assertFalse( $replacer->is_enabled() );
		$this->assertSame( '/core/orders.php', $replacer->replace( '/core/orders.php', 'myaccount/orders.php' ) );
	}

	/**
	 * Asked for, and with a clean theme, the swap happens.
	 *
	 * @return void
	 */
	public function test_replacement_swaps_the_template_when_requested(): void {
		$replacer = $this->replacer( true );

		$this->assertTrue( $replacer->is_enabled() );
		$this->assertSame(
			WPMPHUB_PLUGIN_DIR . 'templates/myaccount/orders.php',
			$replacer->replace( '/core/orders.php', 'myaccount/orders.php' )
		);
	}

	/**
	 * A theme that owns the template keeps it, however the setting reads.
	 *
	 * @return void
	 */
	public function test_a_theme_conflict_refuses_the_swap(): void {
		$this->add_theme_conflict();

		$replacer = $this->replacer( true );

		$this->assertTrue( $replacer->is_requested() );
		$this->assertFalse( $replacer->is_enabled() );
		$this->assertSame( '/core/orders.php', $replacer->replace( '/core/orders.php', 'myaccount/orders.php' ) );
	}

	/**
	 * The conflict is refused for every watched template, not just the clashing one.
	 *
	 * @return void
	 */
	public function test_one_conflict_disables_replacement_entirely(): void {
		$this->add_theme_conflict();

		$replacer = $this->replacer( true );

		$this->assertSame(
			'/core/view-order.php',
			$replacer->replace( '/core/view-order.php', 'myaccount/view-order.php' )
		);
	}

	/**
	 * Templates we do not replace pass straight through, even when enabled.
	 *
	 * @dataProvider untouched_templates
	 *
	 * @param string $name Template name.
	 * @return void
	 */
	public function test_other_templates_are_never_touched( string $name ): void {
		$replacer = $this->replacer( true );

		$this->assertSame( '/core/whatever.php', $replacer->replace( '/core/whatever.php', $name ) );
	}

	/**
	 * WooCommerce templates this plugin has no opinion about.
	 *
	 * @return array<string, array{string}>
	 */
	public static function untouched_templates(): array {
		return array(
			'cart'         => array( 'cart/cart.php' ),
			'checkout'     => array( 'checkout/form-checkout.php' ),
			'dashboard'    => array( 'myaccount/dashboard.php' ),
			'single order' => array( 'order/order-details.php' ),
			'empty name'   => array( '' ),
		);
	}

	/**
	 * An unrecognised mode is treated as additive, never as replacement.
	 *
	 * @return void
	 */
	public function test_an_unknown_mode_falls_back_to_additive(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( TemplateReplacer::SETTING => 'something-else' );

		$replacer = new TemplateReplacer(
			new TemplateLoader( new Logger() ),
			new TemplateConflictScanner( new Cache() )
		);

		$this->assertFalse( $replacer->is_enabled() );
	}

	/**
	 * A page builder owning the account page is a conflict too.
	 *
	 * A builder-rendered account page never calls WooCommerce's templates, so
	 * swapping them would change nothing while reporting success.
	 *
	 * @dataProvider builder_pages
	 *
	 * @param array<string, string> $meta    Post meta on the My Account page.
	 * @param string                $content Its content.
	 * @param string                $builder Builder that should be named.
	 * @return void
	 */
	public function test_a_page_builder_is_a_conflict( array $meta, string $content, string $builder ): void {
		FakeWordPress::$account_page_id  = 12;
		FakeWordPress::$post_meta[12]    = $meta;
		FakeWordPress::$post_content[12] = $content;

		$scanner = new TemplateConflictScanner( new Cache() );

		$this->assertArrayHasKey( TemplateConflictScanner::BUILDER_PREFIX . $builder, $scanner->conflicts( true ) );

		FakeWordPress::$options['wpmphub_settings'] = array(
			TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT,
		);

		$replacer = new TemplateReplacer( new TemplateLoader( new Logger() ), $scanner );

		$this->assertTrue( $replacer->is_requested() );
		$this->assertFalse( $replacer->is_enabled() );
	}

	/**
	 * The marks each builder leaves on a page it owns.
	 *
	 * @return array<string, array{array<string, string>, string, string}>
	 */
	public static function builder_pages(): array {
		return array(
			'elementor'      => array( array( '_elementor_edit_mode' => 'builder' ), '', 'elementor' ),
			'beaver builder' => array( array( '_fl_builder_enabled' => '1' ), '', 'beaver-builder' ),
			'wpbakery'       => array( array( '_wpb_vc_js_status' => 'true' ), '', 'wpbakery' ),
			'divi'           => array( array(), '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]', 'divi' ),
		);
	}

	/**
	 * A plain account page is not mistaken for a builder's.
	 *
	 * @return void
	 */
	public function test_a_plain_account_page_is_not_a_conflict(): void {
		FakeWordPress::$account_page_id  = 12;
		FakeWordPress::$post_content[12] = '[woocommerce_my_account]';

		$this->assertSame( array(), ( new TemplateConflictScanner( new Cache() ) )->conflicts( true ) );
	}

	/**
	 * The mode is filterable, so a site can force it without the settings screen.
	 *
	 * @return void
	 */
	public function test_the_mode_is_filterable(): void {
		add_filter(
			'wpmphub_template_mode',
			static function (): string {
				return TemplateReplacer::MODE_REPLACEMENT;
			}
		);

		$this->assertTrue( $this->replacer( false )->is_enabled() );
	}
}
