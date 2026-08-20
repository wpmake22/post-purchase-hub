<?php
/**
 * Service container and hook wiring.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub;

use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;

/**
 * Holds the plugin's services and wires its hooks.
 *
 * Deliberately not a dependency-injection framework: closures registered here
 * are resolved on first use and memoised, which is all a plugin of this size
 * needs. This class carries no business logic — it only builds and wires.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service factories, keyed by service id.
	 *
	 * @var array<string, callable(Plugin): object>
	 */
	private array $factories = array();

	/**
	 * Resolved services, keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether register() has already wired the hooks.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Registers the services every request may need.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->set(
			'logger',
			static function (): Logger {
				return new Logger();
			}
		);

		$this->set(
			'cache',
			static function (): Cache {
				return new Cache();
			}
		);
	}

	/**
	 * Returns the shared instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers a service factory, replacing any previously registered one.
	 *
	 * @since 0.1.0
	 *
	 * @param string                   $id      Service id.
	 * @param callable(Plugin): object $factory Factory resolved on first get().
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * Whether a factory is registered for the given id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolves a service, building it once and reusing it afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \InvalidArgumentException When no factory is registered for the id.
	 */
	public function get( string $id ): object {
		if ( isset( $this->services[ $id ] ) ) {
			return $this->services[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
			throw new \InvalidArgumentException( esc_html( 'Unknown service: ' . $id ) );
		}

		$service = ( $this->factories[ $id ] )( $this );

		$this->services[ $id ] = $service;

		return $service;
	}

	/**
	 * Returns the logger.
	 *
	 * @since 0.1.0
	 *
	 * @return Logger
	 * @throws \UnexpectedValueException When the registered factory returns something else.
	 */
	public function logger(): Logger {
		$service = $this->get( 'logger' );

		if ( ! $service instanceof Logger ) {
			throw new \UnexpectedValueException( 'The logger factory must return a Logger.' );
		}

		return $service;
	}

	/**
	 * Returns the cache.
	 *
	 * @since 0.1.0
	 *
	 * @return Cache
	 * @throws \UnexpectedValueException When the registered factory returns something else.
	 */
	public function cache(): Cache {
		$service = $this->get( 'cache' );

		if ( ! $service instanceof Cache ) {
			throw new \UnexpectedValueException( 'The cache factory must return a Cache.' );
		}

		return $service;
	}

	/**
	 * Wires the plugin's hooks. Safe to call more than once.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the bundled translations.
	 *
	 * The Pro distribution is not hosted on WordPress.org, so its translations
	 * ship inside the plugin and have to be registered explicitly.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'post-purchase-hub', false, dirname( plugin_basename( PPH_PLUGIN_FILE ) ) . '/languages' );
	}
}
