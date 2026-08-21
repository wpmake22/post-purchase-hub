<?php
/**
 * Container unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Plugin;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__ ) . '/stubs/wp-functions.php';

/**
 * Covers the container contract every later milestone builds on.
 *
 * @since 0.1.0
 *
 * @covers \PostPurchaseHub\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * The default services resolve to their own types.
	 *
	 * @return void
	 */
	public function test_it_resolves_the_default_services(): void {
		$plugin = new Plugin();

		$this->assertInstanceOf( Logger::class, $plugin->logger() );
		$this->assertInstanceOf( Cache::class, $plugin->cache() );
	}

	/**
	 * A factory runs once and the instance is reused.
	 *
	 * @return void
	 */
	public function test_it_resolves_each_service_once(): void {
		$plugin = new Plugin();
		$calls  = 0;

		$plugin->set(
			'counter',
			static function () use ( &$calls ): object {
				++$calls;

				return new \stdClass();
			}
		);

		$first = $plugin->get( 'counter' );

		$this->assertSame( $first, $plugin->get( 'counter' ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Factories are lazy: registering one resolves nothing.
	 *
	 * @return void
	 */
	public function test_registration_does_not_resolve(): void {
		$plugin   = new Plugin();
		$resolved = false;

		$plugin->set(
			'lazy',
			static function () use ( &$resolved ): object {
				$resolved = true;

				return new \stdClass();
			}
		);

		$this->assertFalse( $resolved );
		$this->assertTrue( $plugin->has( 'lazy' ) );

		$plugin->get( 'lazy' );

		$this->assertTrue( $resolved );
	}

	/**
	 * The container hands itself to factories so services can depend on each other.
	 *
	 * @return void
	 */
	public function test_a_factory_receives_the_container(): void {
		$plugin = new Plugin();

		$plugin->set(
			'dependent',
			static function ( Plugin $container ): object {
				return $container->cache();
			}
		);

		$this->assertSame( $plugin->cache(), $plugin->get( 'dependent' ) );
	}

	/**
	 * Replacing a factory discards an instance already built from the old one.
	 *
	 * @return void
	 */
	public function test_replacing_a_factory_discards_the_resolved_service(): void {
		$plugin = new Plugin();
		$first  = $plugin->cache();

		$plugin->set(
			'cache',
			static function (): object {
				return new Cache();
			}
		);

		$this->assertNotSame( $first, $plugin->cache() );
	}

	/**
	 * An unknown service is a programming error, not a null.
	 *
	 * @return void
	 */
	public function test_an_unknown_service_throws(): void {
		$plugin = new Plugin();

		$this->assertFalse( $plugin->has( 'nope' ) );

		$this->expectException( \InvalidArgumentException::class );

		$plugin->get( 'nope' );
	}

	/**
	 * A service factory returning the wrong type is caught at the accessor.
	 *
	 * @return void
	 */
	public function test_a_mistyped_service_throws(): void {
		$plugin = new Plugin();

		$plugin->set(
			'logger',
			static function (): object {
				return new \stdClass();
			}
		);

		$this->expectException( \UnexpectedValueException::class );

		$plugin->logger();
	}

	/**
	 * Register() wires its hooks exactly once, however often it is called.
	 *
	 * @return void
	 */
	public function test_register_is_idempotent(): void {
		$plugin = new Plugin();

		$plugin->register();

		$after_first = FakeWordPress::$actions;

		$plugin->register();

		// Compared wholesale rather than counted, so a hook added by a later
		// milestone cannot quietly stop this from testing what it says.
		$this->assertEquals( $after_first, FakeWordPress::$actions );
		$this->assertContains(
			array(
				'callback' => array( $plugin, 'load_textdomain' ),
				'priority' => 10,
			),
			FakeWordPress::$actions['init']
		);
	}

	/**
	 * Instance() is the same object every time.
	 *
	 * @return void
	 */
	public function test_instance_is_shared(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}
}
