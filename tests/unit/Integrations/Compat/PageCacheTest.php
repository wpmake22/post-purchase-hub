<?php
/**
 * PageCache unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Integrations\Compat;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Frontend\GuestContext;
use PostPurchaseHub\Integrations\Compat\PageCache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 3 ) . '/stubs/wp-functions.php';

/**
 * Covers the acceptance criterion "page is not served from cache by WP Rocket
 * or LiteSpeed" as far as source can: the cookie that suppresses caching is
 * offered to each plugin's own exclusion filter, and offering it neither
 * discards what that plugin already excluded nor adds it twice.
 *
 * The other half of that criterion is a live check with both plugins installed,
 * which no unit test can stand in for.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Integrations\Compat\PageCache
 */
final class PageCacheTest extends TestCase {

	/**
	 * Compat layer under test.
	 *
	 * @var PageCache
	 */
	private PageCache $compat;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->compat = new PageCache();
	}

	/**
	 * Every supported cache plugin's filter is hooked.
	 *
	 * @return void
	 */
	public function test_it_registers_with_each_supported_cache_plugin(): void {
		$this->compat->register();

		foreach ( array( 'rocket_cache_reject_cookies', 'litespeed_vary_cookies', 'w3tc_pgcache_reject_cookies' ) as $hook ) {
			$this->assertArrayHasKey( $hook, FakeWordPress::$filters, $hook . ' must be filtered.' );
		}
	}

	/**
	 * The context cookie is added to an empty list.
	 *
	 * @return void
	 */
	public function test_it_adds_the_context_cookie(): void {
		$this->assertSame( array( GuestContext::COOKIE ), $this->compat->add_cookie( array() ) );
	}

	/**
	 * Cookies another plugin already excluded are kept.
	 *
	 * @return void
	 */
	public function test_it_keeps_what_the_cache_plugin_already_excluded(): void {
		$this->assertSame(
			array( 'wordpress_logged_in', 'woocommerce_items_in_cart', GuestContext::COOKIE ),
			$this->compat->add_cookie( array( 'wordpress_logged_in', 'woocommerce_items_in_cart' ) )
		);
	}

	/**
	 * Running twice does not add the cookie twice.
	 *
	 * @return void
	 */
	public function test_it_does_not_duplicate_the_cookie(): void {
		$this->assertSame(
			array( GuestContext::COOKIE ),
			$this->compat->add_cookie( $this->compat->add_cookie( array() ) )
		);
	}

	/**
	 * A cache plugin handing over something that is not a list still gets a
	 * usable one back.
	 *
	 * @return void
	 */
	public function test_it_survives_a_non_array_value(): void {
		$this->assertSame( array( GuestContext::COOKIE ), $this->compat->add_cookie( null ) );
	}
}
