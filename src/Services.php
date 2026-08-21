<?php
/**
 * Service definitions.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub;

use PostPurchaseHub\Actions\ActionRegistry;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Frontend\ActionsRenderer;
use PostPurchaseHub\Frontend\Assets;
use PostPurchaseHub\Frontend\Blocks;
use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\RequestModalRenderer;
use PostPurchaseHub\Frontend\Shortcodes;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Requests\RequestService;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Rest\RequestsController;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PostPurchaseHub\Timeline\TimelineBuilder;
use PostPurchaseHub\Timeline\TransitionRecorder;

/**
 * Declares what this plugin is made of and how each part is built.
 *
 * Split out of Plugin so the container keeps its own shape as the plugin grows:
 * one file answers "how does resolution work", this one answers "what is there
 * to resolve". Every factory is a closure, so nothing here is constructed until
 * something asks for it.
 *
 * @since 0.4.0
 */
final class Services {

	/**
	 * Registers every service factory on a container.
	 *
	 * @since 0.4.0
	 *
	 * @param Plugin $plugin Container to populate.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {

		$plugin->set(
			'logger',
			static function (): Logger {
				return new Logger();
			}
		);

		$plugin->set(
			'cache',
			static function (): Cache {
				return new Cache();
			}
		);

		$plugin->set(
			'migrator',
			static function ( Plugin $plugin ): Migrator {
				return new Migrator( $plugin->logger() );
			}
		);

		$plugin->set(
			'requests',
			static function (): RequestRepository {
				return new RequestRepository();
			}
		);

		$plugin->set(
			'sweeper',
			static function ( Plugin $plugin ): RetentionSweeper {
				return new RetentionSweeper( $plugin->logger() );
			}
		);

		$plugin->set(
			'stage_map',
			static function ( Plugin $plugin ): StageMap {
				return new StageMap( new StatusDetector( $plugin->cache() ) );
			}
		);

		$plugin->set(
			'transition_recorder',
			static function ( Plugin $plugin ): TransitionRecorder {
				return new TransitionRecorder( $plugin->stage_map(), $plugin->logger() );
			}
		);

		$plugin->set(
			'timeline_builder',
			static function ( Plugin $plugin ): TimelineBuilder {
				return new TimelineBuilder( $plugin->stage_map(), $plugin->transition_recorder() );
			}
		);

		$plugin->set(
			'templates',
			static function ( Plugin $plugin ): TemplateLoader {
				return new TemplateLoader( $plugin->logger() );
			}
		);

		$plugin->set(
			'tracking_availability',
			static function (): TrackingAvailability {
				return new NullTrackingAvailability();
			}
		);

		$plugin->set(
			'estimated_delivery',
			static function ( Plugin $plugin ): EstimatedDelivery {
				return new EstimatedDelivery( $plugin->tracking_availability(), $plugin->logger() );
			}
		);

		$plugin->set(
			'pending_cancellation_branch',
			static function ( Plugin $plugin ): PendingCancellationBranch {
				return new PendingCancellationBranch( $plugin->requests() );
			}
		);

		$plugin->set(
			'renderer',
			static function ( Plugin $plugin ): Renderer {
				return new Renderer(
					$plugin->timeline_builder(),
					$plugin->templates(),
					$plugin->estimated_delivery(),
					$plugin->pending_cancellation_branch()
				);
			}
		);

		$plugin->set(
			'assets',
			static function (): Assets {
				return new Assets();
			}
		);

		$plugin->set(
			'shortcodes',
			static function ( Plugin $plugin ): Shortcodes {
				return new Shortcodes( $plugin->renderer() );
			}
		);

		$plugin->set(
			'blocks',
			static function ( Plugin $plugin ): Blocks {
				return new Blocks( $plugin->shortcodes() );
			}
		);

		$plugin->set(
			'conflict_scanner',
			static function ( Plugin $plugin ): TemplateConflictScanner {
				return new TemplateConflictScanner( $plugin->cache() );
			}
		);

		$plugin->set(
			'template_replacer',
			static function ( Plugin $plugin ): TemplateReplacer {
				return new TemplateReplacer( $plugin->templates(), $plugin->conflict_scanner() );
			}
		);

		$plugin->set(
			'tokens',
			static function (): TokenService {
				return new TokenService();
			}
		);

		$plugin->set(
			'rate_limiter',
			static function ( Plugin $plugin ): RateLimiter {
				return new RateLimiter( $plugin->cache() );
			}
		);

		$plugin->set(
			'ownership_resolver',
			static function ( Plugin $plugin ): OwnershipResolver {
				return new OwnershipResolver( $plugin->tokens() );
			}
		);

		$plugin->set(
			'action_registry',
			static function (): ActionRegistry {
				return new ActionRegistry();
			}
		);

		$plugin->set(
			'eligibility_resolver',
			static function ( Plugin $plugin ): EligibilityResolver {
				return new EligibilityResolver( $plugin->requests() );
			}
		);

		$plugin->set(
			'actions_renderer',
			static function ( Plugin $plugin ): ActionsRenderer {
				return new ActionsRenderer( $plugin->action_registry(), $plugin->templates() );
			}
		);

		$plugin->set(
			'request_service',
			static function ( Plugin $plugin ): RequestService {
				return new RequestService( $plugin->requests() );
			}
		);

		$plugin->set(
			'cancel',
			static function ( Plugin $plugin ): Cancel {
				return new Cancel( $plugin->eligibility_resolver(), $plugin->request_service() );
			}
		);

		$plugin->set(
			'requests_controller',
			static function ( Plugin $plugin ): RequestsController {
				return new RequestsController(
					$plugin->ownership_resolver(),
					$plugin->rate_limiter(),
					$plugin->request_service(),
					$plugin->cancel(),
					$plugin->logger()
				);
			}
		);

		$plugin->set(
			'request_modal_renderer',
			static function ( Plugin $plugin ): RequestModalRenderer {
				return new RequestModalRenderer( $plugin->templates(), $plugin->assets() );
			}
		);
	}
}
