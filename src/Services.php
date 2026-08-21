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
use PostPurchaseHub\Actions\CartGateway;
use PostPurchaseHub\Actions\EligibilityResolver;
use PostPurchaseHub\Actions\Help;
use PostPurchaseHub\Actions\HelpContextBuilder;
use PostPurchaseHub\Actions\Invoice;
use PostPurchaseHub\Actions\Reorder;
use PostPurchaseHub\Actions\ReorderPlanner;
use PostPurchaseHub\Actions\WooCommerceCart;
use PostPurchaseHub\Admin\Assets as AdminAssets;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\Menu;
use PostPurchaseHub\Admin\Notices;
use PostPurchaseHub\Admin\SettingsPage;
use PostPurchaseHub\Admin\Wizard;
use PostPurchaseHub\Admin\WizardPreview;
use PostPurchaseHub\Admin\WizardScreen;
use PostPurchaseHub\Admin\WizardSteps;
use PostPurchaseHub\Admin\OrderMetabox;
use PostPurchaseHub\Admin\RequestActionController;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Emails\Mailer;
use PostPurchaseHub\Frontend\ActionsRenderer;
use PostPurchaseHub\Frontend\Assets;
use PostPurchaseHub\Frontend\Blocks;
use PostPurchaseHub\Frontend\GuestContext;
use PostPurchaseHub\Frontend\GuestOrderView;
use PostPurchaseHub\Frontend\HelpView;
use PostPurchaseHub\Frontend\LookupForm;
use PostPurchaseHub\Frontend\Renderer;
use PostPurchaseHub\Frontend\ReorderView;
use PostPurchaseHub\Frontend\RequestModalRenderer;
use PostPurchaseHub\Frontend\Shortcodes;
use PostPurchaseHub\Frontend\TemplateLoader;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Integrations\Compat\PageCache;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Tracking\NullTrackingAvailability;
use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Requests\RequestService;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Rest\HelpController;
use PostPurchaseHub\Rest\LookupController;
use PostPurchaseHub\Rest\ReorderController;
use PostPurchaseHub\Rest\RequestsController;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\RateLimiter;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Support\Logger;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;
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
				return new Blocks( $plugin->shortcodes(), $plugin->lookup_form() );
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
			'cart',
			static function ( Plugin $plugin ): CartGateway {
				return new WooCommerceCart( $plugin->logger() );
			}
		);

		$plugin->set(
			'reorder_planner',
			static function (): ReorderPlanner {
				return new ReorderPlanner();
			}
		);

		$plugin->set(
			'reorder',
			static function ( Plugin $plugin ): Reorder {
				return new Reorder( $plugin->eligibility_resolver(), $plugin->reorder_planner(), $plugin->cart() );
			}
		);

		$plugin->set(
			'reorder_controller',
			static function ( Plugin $plugin ): ReorderController {
				return new ReorderController(
					$plugin->ownership_resolver(),
					$plugin->rate_limiter(),
					$plugin->reorder(),
					$plugin->cart(),
					$plugin->logger()
				);
			}
		);

		$plugin->set(
			'reorder_view',
			static function ( Plugin $plugin ): ReorderView {
				return new ReorderView( $plugin->reorder(), $plugin->cart(), $plugin->templates() );
			}
		);

		$plugin->set(
			'invoice_detector',
			static function ( Plugin $plugin ): Detector {
				return new Detector( $plugin->cache() );
			}
		);

		$plugin->set(
			'invoice',
			static function ( Plugin $plugin ): Invoice {
				return new Invoice( $plugin->eligibility_resolver(), $plugin->invoice_detector() );
			}
		);

		$plugin->set(
			'help_context_builder',
			static function ( Plugin $plugin ): HelpContextBuilder {
				return new HelpContextBuilder( $plugin->timeline_builder() );
			}
		);

		$plugin->set(
			'help',
			static function ( Plugin $plugin ): Help {
				return new Help( $plugin->eligibility_resolver(), $plugin->help_context_builder() );
			}
		);

		$plugin->set(
			'help_controller',
			static function ( Plugin $plugin ): HelpController {
				return new HelpController(
					$plugin->ownership_resolver(),
					$plugin->rate_limiter(),
					$plugin->help(),
					$plugin->logger()
				);
			}
		);

		$plugin->set(
			'help_view',
			static function ( Plugin $plugin ): HelpView {
				return new HelpView( $plugin->help(), $plugin->templates() );
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

		$plugin->set(
			'menu',
			static function ( Plugin $plugin ): Menu {
				return new Menu( $plugin->requests() );
			}
		);

		$plugin->set(
			'order_metabox',
			static function ( Plugin $plugin ): OrderMetabox {
				return new OrderMetabox( $plugin->requests() );
			}
		);

		$plugin->set(
			'request_action_controller',
			static function ( Plugin $plugin ): RequestActionController {
				return new RequestActionController( $plugin->request_service(), $plugin->cancel(), $plugin->logger() );
			}
		);

		$plugin->set(
			'mailer',
			static function ( Plugin $plugin ): Mailer {
				return new Mailer( $plugin->requests(), $plugin->tokens() );
			}
		);

		$plugin->set(
			'guest_access',
			static function (): GuestAccess {
				return new GuestAccess();
			}
		);

		$plugin->set(
			'order_lookup',
			static function (): OrderLookup {
				return new OrderLookup();
			}
		);

		$plugin->set(
			'guest_lookup_service',
			static function ( Plugin $plugin ): GuestLookupService {
				return new GuestLookupService(
					$plugin->guest_access(),
					$plugin->order_lookup(),
					$plugin->rate_limiter(),
					$plugin->logger()
				);
			}
		);

		$plugin->set(
			'lookup_controller',
			static function ( Plugin $plugin ): LookupController {
				return new LookupController( $plugin->guest_access(), $plugin->guest_lookup_service() );
			}
		);

		$plugin->set(
			'lookup_form',
			static function ( Plugin $plugin ): LookupForm {
				return new LookupForm( $plugin->guest_access(), $plugin->guest_lookup_service(), $plugin->templates() );
			}
		);

		$plugin->set(
			'guest_context',
			static function ( Plugin $plugin ): GuestContext {
				return new GuestContext( $plugin->tokens(), $plugin->cache(), $plugin->logger() );
			}
		);

		$plugin->set(
			'guest_order_view',
			static function ( Plugin $plugin ): GuestOrderView {
				return new GuestOrderView( $plugin->ownership_resolver(), $plugin->templates() );
			}
		);

		$plugin->set(
			'stage_map_config',
			static function (): StageMapConfig {
				return new StageMapConfig();
			}
		);

		$plugin->set(
			'health_panel',
			static function ( Plugin $plugin ): HealthPanel {
				return new HealthPanel( $plugin->conflict_scanner(), $plugin->invoice_detector() );
			}
		);

		$plugin->set(
			'settings_page',
			static function ( Plugin $plugin ): SettingsPage {
				return new SettingsPage( $plugin->stage_map(), $plugin->health_panel() );
			}
		);

		$plugin->set(
			'wizard_preview',
			static function ( Plugin $plugin ): WizardPreview {
				return new WizardPreview( $plugin->renderer() );
			}
		);

		$plugin->set(
			'wizard_steps',
			static function ( Plugin $plugin ): WizardSteps {
				return new WizardSteps( $plugin->wizard_preview() );
			}
		);

		$plugin->set(
			'wizard_screen',
			static function ( Plugin $plugin ): WizardScreen {
				return new WizardScreen( $plugin->wizard_steps() );
			}
		);

		$plugin->set(
			'wizard',
			static function ( Plugin $plugin ): Wizard {
				return new Wizard( $plugin->stage_map(), $plugin->health_panel(), $plugin->wizard_screen() );
			}
		);

		$plugin->set(
			'admin_assets',
			static function (): AdminAssets {
				return new AdminAssets();
			}
		);

		$plugin->set(
			'notices',
			static function (): Notices {
				return new Notices();
			}
		);

		$plugin->set(
			'page_cache',
			static function (): PageCache {
				return new PageCache();
			}
		);
	}
}
