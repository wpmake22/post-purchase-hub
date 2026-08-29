<?php
/**
 * Template conflict scanner integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Support\Cache;

/**
 * Exercises the scan against a theme that really does own the templates.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Admin\TemplateConflictScanner
 * @covers \PostPurchaseHub\Frontend\TemplateReplacer
 */
final class TemplateConflictScannerTest extends \WP_UnitTestCase {

	/**
	 * Points the theme directories at the fixture theme.
	 *
	 * @return void
	 */
	private function use_conflicting_theme(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/theme';

		$path = static function () use ( $fixture ): string {
			return $fixture;
		};

		add_filter( 'stylesheet_directory', $path );
		add_filter( 'template_directory', $path );

		// locate_template() has read $wp_stylesheet_path/$wp_template_path
		// rather than calling these filters since WP 6.4, and those globals are
		// populated once during bootstrap. switch_theme() the *function*
		// refreshes them; the switch_theme *action* this test fires does not.
		// Without this the filters are inert and the fixture theme is invisible,
		// so the scanner is asked to find a conflict that is not reachable.
		wp_set_template_globals();
	}

	/**
	 * A stock theme owns neither template.
	 *
	 * @return void
	 */
	public function test_a_clean_theme_reports_no_conflicts(): void {
		$scanner = new TemplateConflictScanner( new Cache() );

		$this->assertSame( array(), $scanner->conflicts( true ) );
		$this->assertFalse( $scanner->has_conflicts( true ) );
	}

	/**
	 * A theme that copied a watched template is reported, with its path.
	 *
	 * @return void
	 */
	public function test_a_theme_override_is_detected(): void {
		$this->use_conflicting_theme();

		$scanner   = new TemplateConflictScanner( new Cache() );
		$conflicts = $scanner->conflicts( true );

		$this->assertArrayHasKey( 'myaccount/orders.php', $conflicts );
		$this->assertStringEndsWith( 'fixtures/theme/woocommerce/myaccount/orders.php', $conflicts['myaccount/orders.php'] );
		$this->assertArrayNotHasKey( 'myaccount/view-order.php', $conflicts );
	}

	/**
	 * Replacement mode refuses to take effect while a conflict stands.
	 *
	 * @return void
	 */
	public function test_replacement_refuses_to_enable_on_a_conflict(): void {
		$this->use_conflicting_theme();

		update_option( 'wpmphub_settings', array( TemplateReplacer::SETTING => TemplateReplacer::MODE_REPLACEMENT ) );

		$plugin = new Plugin();
		$plugin->conflict_scanner()->conflicts( true );

		$replacer = $plugin->template_replacer();
		$replacer->register();

		$this->assertTrue( $replacer->is_requested(), 'the merchant did ask for replacement' );
		$this->assertFalse( $replacer->is_enabled(), 'but the theme owns the template' );

		$this->assertSame(
			'/core/orders.php',
			apply_filters( 'wc_get_template', '/core/orders.php', 'myaccount/orders.php', array(), '', '' )
		);
	}

	/**
	 * The scan is cached, and switching theme drops it.
	 *
	 * @return void
	 */
	public function test_the_scan_is_cached_and_cleared_on_theme_switch(): void {
		$scanner = new TemplateConflictScanner( new Cache() );

		$this->assertSame( array(), $scanner->conflicts() );

		$this->use_conflicting_theme();

		$this->assertSame( array(), $scanner->conflicts(), 'the cached answer should stand' );

		$scanner->register();
		do_action( 'switch_theme', 'another-theme' );

		$this->assertArrayHasKey( 'myaccount/orders.php', $scanner->conflicts() );
	}
}
