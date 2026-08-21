<?php
/**
 * Service container and hook wiring.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub;

use PostPurchaseHub\CLI\BackfillCommand;
use PostPurchaseHub\CLI\CleanupCommand;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

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

		$this->set(
			'migrator',
			static function ( Plugin $plugin ): Migrator {
				return new Migrator( $plugin->logger() );
			}
		);

		$this->set(
			'requests',
			static function (): RequestRepository {
				return new RequestRepository();
			}
		);

		$this->set(
			'sweeper',
			static function ( Plugin $plugin ): RetentionSweeper {
				return new RetentionSweeper( $plugin->logger() );
			}
		);

		$this->set(
			'stage_map',
			static function ( Plugin $plugin ): StageMap {
				return new StageMap( new StatusDetector( $plugin->cache() ) );
			}
		);

		$this->set(
			'transition_recorder',
			static function ( Plugin $plugin ): TransitionRecorder {
				return new TransitionRecorder( $plugin->stage_map(), $plugin->logger() );
			}
		);

		$this->set(
			'timeline_builder',
			static function ( Plugin $plugin ): TimelineBuilder {
				return new TimelineBuilder( $plugin->stage_map(), $plugin->transition_recorder() );
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
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->typed( 'logger', Logger::class );
	}

	/**
	 * Returns the cache.
	 *
	 * @since 0.1.0
	 * @return Cache
	 */
	public function cache(): Cache {
		return $this->typed( 'cache', Cache::class );
	}

	/**
	 * Returns the migration runner.
	 *
	 * @since 0.2.0
	 * @return Migrator
	 */
	public function migrator(): Migrator {
		return $this->typed( 'migrator', Migrator::class );
	}

	/**
	 * Returns the request repository.
	 *
	 * @since 0.2.0
	 * @return RequestRepository
	 */
	public function requests(): RequestRepository {
		return $this->typed( 'requests', RequestRepository::class );
	}

	/**
	 * Returns the retention sweeper.
	 *
	 * @since 0.2.0
	 * @return RetentionSweeper
	 */
	public function sweeper(): RetentionSweeper {
		return $this->typed( 'sweeper', RetentionSweeper::class );
	}

	/**
	 * Returns the timeline stage definitions.
	 *
	 * @since 0.3.0
	 * @return StageMap
	 */
	public function stage_map(): StageMap {
		return $this->typed( 'stage_map', StageMap::class );
	}

	/**
	 * Returns the status transition recorder.
	 *
	 * @since 0.3.0
	 * @return TransitionRecorder
	 */
	public function transition_recorder(): TransitionRecorder {
		return $this->typed( 'transition_recorder', TransitionRecorder::class );
	}

	/**
	 * Returns the timeline builder.
	 *
	 * @since 0.3.0
	 * @return TimelineBuilder
	 */
	public function timeline_builder(): TimelineBuilder {
		return $this->typed( 'timeline_builder', TimelineBuilder::class );
	}

	/**
	 * Resolves a service and asserts what came back.
	 *
	 * A factory can be replaced through set(), so the type is checked once here
	 * rather than assumed in every accessor.
	 *
	 * @since 0.2.0
	 *
	 * @template T of object
	 * @param string $id       Service id.
	 * @param string $expected Expected class name.
	 * @phpstan-param class-string<T> $expected
	 * @phpstan-return T
	 * @return object
	 * @throws \UnexpectedValueException When the factory returns another type.
	 */
	private function typed( string $id, string $expected ): object {
		$service = $this->get( $id );

		if ( ! $service instanceof $expected ) {
			// esc_html() per WordPress standards: exception messages can surface in a fatal-error screen.
			throw new \UnexpectedValueException( esc_html( 'Service ' . $id . ' must be an instance of ' . $expected . '.' ) );
		}

		return $service;
	}

	/**
	 * Wires the plugin's hooks. Safe to call more than once.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Priority 20: this method itself runs on plugins_loaded, and the schema
		// check has to land after every plugin has had its chance to load.
		add_action( 'plugins_loaded', array( $this, 'check_schema' ), 20 );

		add_action( Activator::CLEANUP_HOOK, array( $this, 'run_cleanup' ) );

		add_action( 'woocommerce_order_status_changed', array( $this, 'record_transition' ), 10, 4 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pph cleanup', new CleanupCommand( $this->sweeper() ) );
			\WP_CLI::add_command( 'pph backfill-timeline', new BackfillCommand( $this->transition_recorder(), $this->stage_map() ) );
		}
	}

	/**
	 * Brings the schema up to date when this build is ahead of the site.
	 *
	 * @since 0.2.0
	 * @return void
	 */
	public function check_schema(): void {
		$this->migrator()->maybe_migrate();
	}

	/**
	 * Records a status transition on the order's timeline.
	 *
	 * Wired here rather than inside the recorder so the services stay unbuilt on
	 * the overwhelming majority of requests, where no order changes status.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved away from.
	 * @param string $to       Status moved to.
	 * @param mixed  $order    Order object as passed by WooCommerce.
	 * @return void
	 */
	public function record_transition( $order_id, $from, $to, $order = null ): void {
		$this->transition_recorder()->record( (int) $order_id, (string) $from, (string) $to, $order );
	}

	/**
	 * Runs the daily retention sweep.
	 *
	 * @since 0.2.0
	 * @return void
	 */
	public function run_cleanup(): void {
		$this->sweeper()->sweep( RetentionSweeper::CRON_BATCHES );
	}

	/**
	 * Loads the bundled translations.
	 *
	 * The Pro distribution is not hosted on WordPress.org, so its translations
	 * ship inside the plugin and have to be registered explicitly.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'post-purchase-hub', false, dirname( plugin_basename( PPH_PLUGIN_FILE ) ) . '/languages' );
	}
}
