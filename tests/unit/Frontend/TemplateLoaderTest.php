<?php
/**
 * Template loader unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Frontend;

use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the allow list, the override order and the traversal attempts that
 * must never resolve.
 *
 * @since 0.4.0
 *
 * @covers \PostPurchaseHub\Frontend\TemplateLoader
 */
final class TemplateLoaderTest extends TestCase {

	/**
	 * Loader under test.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $loader;

	/**
	 * Builds the loader over a fresh fake WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->loader = new TemplateLoader( new Logger() );
	}

	/**
	 * A shipped template resolves to the plugin's own copy.
	 *
	 * @return void
	 */
	public function test_a_known_template_resolves_to_the_plugin_copy(): void {
		$this->assertSame(
			PPH_PLUGIN_DIR . 'templates/partials/timeline.php',
			$this->loader->locate( 'partials/timeline.php' )
		);
	}

	/**
	 * Every name on the allow list actually ships.
	 *
	 * @return void
	 */
	public function test_every_allowed_template_exists_on_disk(): void {
		foreach ( array( 'partials/timeline.php', 'partials/timeline-summary.php', 'partials/orders-list.php', 'myaccount/orders.php', 'myaccount/view-order.php' ) as $name ) {
			$this->assertTrue( $this->loader->is_known( $name ), $name . ' should be on the allow list' );
			$this->assertNotNull( $this->loader->locate( $name ), $name . ' should resolve' );
		}
	}

	/**
	 * The theme wins over the plugin's copy.
	 *
	 * @return void
	 */
	public function test_a_theme_override_takes_precedence(): void {
		FakeWordPress::$theme_templates = array(
			'wpmake-post-purchase-hub/partials/timeline.php' => __FILE__,
		);

		$this->assertSame( __FILE__, $this->loader->locate( 'partials/timeline.php' ) );
	}

	/**
	 * A name that is not on the list resolves to nothing, whatever it looks like.
	 *
	 * @dataProvider rejected_names
	 *
	 * @param string $name Template name.
	 * @return void
	 */
	public function test_names_off_the_allow_list_are_refused( string $name ): void {
		$this->assertFalse( $this->loader->is_known( $name ) );
		$this->assertNull( $this->loader->locate( $name ) );
	}

	/**
	 * Names an attacker would try, and names that are merely wrong.
	 *
	 * @return array<string, array{string}>
	 */
	public static function rejected_names(): array {
		return array(
			'traversal'           => array( '../../../wp-config.php' ),
			'traversal in subdir' => array( 'partials/../../../../wp-config.php' ),
			'encoded traversal'   => array( '..%2F..%2Fwp-config.php' ),
			'absolute path'       => array( '/etc/passwd' ),
			'null byte'           => array( "partials/timeline.php\0.jpg" ),
			'unknown template'    => array( 'partials/nope.php' ),
			'empty'               => array( '' ),
			'php in theme root'   => array( 'functions.php' ),
		);
	}

	/**
	 * A traversal attempt is refused even when the file it points at exists.
	 *
	 * @return void
	 */
	public function test_traversal_is_refused_even_when_the_target_exists(): void {
		$this->assertFileExists( PPH_PLUGIN_DIR . 'wpmake-post-purchase-hub.php' );
		$this->assertNull( $this->loader->locate( '../wpmake-post-purchase-hub.php' ) );
	}

	/**
	 * The filter can redirect a template, which is how Pro overrides one.
	 *
	 * @return void
	 */
	public function test_the_filter_can_redirect_a_template(): void {
		add_filter(
			'pph_locate_template',
			static function () {
				return __FILE__;
			}
		);

		$this->assertSame( __FILE__, $this->loader->locate( 'partials/timeline.php' ) );
	}

	/**
	 * A filter pointing at nothing readable is discarded, not rendered.
	 *
	 * @return void
	 */
	public function test_an_unreadable_filtered_path_falls_back(): void {
		add_filter(
			'pph_locate_template',
			static function () {
				return '/definitely/not/here.php';
			}
		);

		$this->assertSame(
			PPH_PLUGIN_DIR . 'templates/partials/timeline.php',
			$this->loader->locate( 'partials/timeline.php' )
		);
	}

	/**
	 * Resolution is memoised, because the orders list asks once per row.
	 *
	 * @return void
	 */
	public function test_resolution_is_memoised(): void {
		$first = $this->loader->locate( 'partials/timeline.php' );

		// A theme appearing mid-request is not a real scenario; a second
		// filesystem lookup per row is. Same answer means it was not repeated.
		FakeWordPress::$theme_templates = array(
			'wpmake-post-purchase-hub/partials/timeline.php' => __FILE__,
		);

		$this->assertSame( $first, $this->loader->locate( 'partials/timeline.php' ) );
	}
}
