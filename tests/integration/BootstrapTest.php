<?php
/**
 * Requirement-guard and bootstrap integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Plugin;

/**
 * Covers the decision the plugin makes before it does anything else: whether
 * this site can be supported at all.
 *
 * @since 0.1.0
 */
final class BootstrapTest extends \WP_UnitTestCase {

	/**
	 * The environment the suite runs in satisfies every requirement, which is
	 * also what proves the guard is not failing open.
	 *
	 * @return void
	 */
	public function test_the_test_environment_meets_every_requirement(): void {
		$this->assertSame(
			array(),
			pph_requirement_failures(
				PHP_VERSION,
				(string) get_bloginfo( 'version' ),
				defined( 'WC_VERSION' ) ? (string) WC_VERSION : null
			)
		);
	}

	/**
	 * A site without WooCommerce is told so, and told nothing else.
	 *
	 * @return void
	 */
	public function test_absent_woocommerce_is_the_only_failure_reported(): void {
		$failures = pph_requirement_failures( PHP_VERSION, (string) get_bloginfo( 'version' ), null );

		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'WooCommerce', $failures[0] );
	}

	/**
	 * Every unmet version requirement is reported, not just the first.
	 *
	 * @return void
	 */
	public function test_each_unmet_version_requirement_is_reported(): void {
		$failures = pph_requirement_failures( '8.0.30', '6.4', '9.9.0' );

		$this->assertCount( 3, $failures );
	}

	/**
	 * Versions at the supported floor pass.
	 *
	 * @return void
	 */
	public function test_the_minimum_supported_versions_pass(): void {
		$this->assertSame(
			array(),
			pph_requirement_failures( PPH_MINIMUM_PHP, PPH_MINIMUM_WP, PPH_MINIMUM_WC )
		);
	}

	/**
	 * A plugin copy without its autoloader says so rather than fatalling.
	 *
	 * @return void
	 */
	public function test_a_missing_autoloader_is_reported(): void {
		$failures = pph_requirement_failures(
			PHP_VERSION,
			(string) get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
			false
		);

		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'autoloader', $failures[0] );
	}

	/**
	 * The notice escapes what it prints, even though the text is our own.
	 *
	 * @return void
	 */
	public function test_the_notice_escapes_its_output(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		pph_requirements_notice( array( '<script>alert(1)</script>' ) );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * The notice is only shown to users who can act on it.
	 *
	 * @return void
	 */
	public function test_the_notice_is_capability_gated(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		pph_requirements_notice( array( 'Requires WooCommerce.' ) );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Nothing is printed when there is nothing to report.
	 *
	 * @return void
	 */
	public function test_the_notice_prints_nothing_without_failures(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		pph_requirements_notice( array() );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * On a supported site the plugin bootstrapped and wired its hooks.
	 *
	 * @return void
	 */
	public function test_the_plugin_bootstrapped_on_this_site(): void {
		$this->assertNotFalse( has_action( 'init', array( Plugin::instance(), 'register_rendering' ) ) );
	}

	/**
	 * HPOS compatibility is declared through the hook WooCommerce requires.
	 *
	 * @return void
	 */
	public function test_hpos_compatibility_is_declared(): void {
		$this->assertNotFalse( has_action( 'before_woocommerce_init', 'pph_declare_hpos_compatibility' ) );
	}
}
