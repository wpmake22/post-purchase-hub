<?php
/**
 * Service container and hook wiring.
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
use PostPurchaseHub\CLI\BackfillCommand;
use PostPurchaseHub\CLI\CleanupCommand;
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
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Migrator;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Integrations\Compat\PageCache;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Integrations\Tracking\TrackingAvailability;
use PostPurchaseHub\Requests\PendingCancellationBranch;
use PostPurchaseHub\Requests\Request;
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
		Services::register( $this );
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
	 * Returns the template loader.
	 *
	 * @since 0.4.0
	 * @return TemplateLoader
	 */
	public function templates(): TemplateLoader {
		return $this->typed( 'templates', TemplateLoader::class );
	}

	/**
	 * Returns the tracking-availability check.
	 *
	 * @since 0.5.0
	 * @return TrackingAvailability
	 */
	public function tracking_availability(): TrackingAvailability {
		return $this->typed( 'tracking_availability', TrackingAvailability::class );
	}

	/**
	 * Returns the estimated-delivery calculator.
	 *
	 * @since 0.5.0
	 * @return EstimatedDelivery
	 */
	public function estimated_delivery(): EstimatedDelivery {
		return $this->typed( 'estimated_delivery', EstimatedDelivery::class );
	}

	/**
	 * Returns the frontend renderer.
	 *
	 * @since 0.4.0
	 * @return Renderer
	 */
	public function renderer(): Renderer {
		return $this->typed( 'renderer', Renderer::class );
	}

	/**
	 * Returns the frontend asset loader.
	 *
	 * @since 0.4.0
	 * @return Assets
	 */
	public function assets(): Assets {
		return $this->typed( 'assets', Assets::class );
	}

	/**
	 * Returns the shortcode service.
	 *
	 * @since 0.4.0
	 * @return Shortcodes
	 */
	public function shortcodes(): Shortcodes {
		return $this->typed( 'shortcodes', Shortcodes::class );
	}

	/**
	 * Returns the block service.
	 *
	 * @since 0.4.0
	 * @return Blocks
	 */
	public function blocks(): Blocks {
		return $this->typed( 'blocks', Blocks::class );
	}

	/**
	 * Returns the template conflict scanner.
	 *
	 * @since 0.4.0
	 * @return TemplateConflictScanner
	 */
	public function conflict_scanner(): TemplateConflictScanner {
		return $this->typed( 'conflict_scanner', TemplateConflictScanner::class );
	}

	/**
	 * Returns the template replacer.
	 *
	 * @since 0.4.0
	 * @return TemplateReplacer
	 */
	public function template_replacer(): TemplateReplacer {
		return $this->typed( 'template_replacer', TemplateReplacer::class );
	}

	/**
	 * Returns the signed-token service.
	 *
	 * @since 0.6.0
	 * @return TokenService
	 */
	public function tokens(): TokenService {
		return $this->typed( 'tokens', TokenService::class );
	}

	/**
	 * Returns the rate limiter.
	 *
	 * @since 0.6.0
	 * @return RateLimiter
	 */
	public function rate_limiter(): RateLimiter {
		return $this->typed( 'rate_limiter', RateLimiter::class );
	}

	/**
	 * Returns the ownership resolver — the only place order access is decided.
	 *
	 * @since 0.6.0
	 * @return OwnershipResolver
	 */
	public function ownership_resolver(): OwnershipResolver {
		return $this->typed( 'ownership_resolver', OwnershipResolver::class );
	}

	/**
	 * Returns the action registry.
	 *
	 * @since 0.7.0
	 * @return ActionRegistry
	 */
	public function action_registry(): ActionRegistry {
		return $this->typed( 'action_registry', ActionRegistry::class );
	}

	/**
	 * Returns the eligibility resolver.
	 *
	 * @since 0.7.0
	 * @return EligibilityResolver
	 */
	public function eligibility_resolver(): EligibilityResolver {
		return $this->typed( 'eligibility_resolver', EligibilityResolver::class );
	}

	/**
	 * Returns the actions renderer.
	 *
	 * @since 0.7.0
	 * @return ActionsRenderer
	 */
	public function actions_renderer(): ActionsRenderer {
		return $this->typed( 'actions_renderer', ActionsRenderer::class );
	}

	/**
	 * Returns the pending-cancellation branch overlay.
	 *
	 * @since 0.8.0
	 * @return PendingCancellationBranch
	 */
	public function pending_cancellation_branch(): PendingCancellationBranch {
		return $this->typed( 'pending_cancellation_branch', PendingCancellationBranch::class );
	}

	/**
	 * Returns the request lifecycle service.
	 *
	 * @since 0.8.0
	 * @return RequestService
	 */
	public function request_service(): RequestService {
		return $this->typed( 'request_service', RequestService::class );
	}

	/**
	 * Returns the cancellation-request action.
	 *
	 * @since 0.8.0
	 * @return Cancel
	 */
	public function cancel(): Cancel {
		return $this->typed( 'cancel', Cancel::class );
	}

	/**
	 * Returns the cart gateway.
	 *
	 * @since 0.12.0
	 * @return CartGateway
	 */
	public function cart(): CartGateway {
		return $this->typed( 'cart', CartGateway::class );
	}

	/**
	 * Returns the reorder planner.
	 *
	 * @since 0.12.0
	 * @return ReorderPlanner
	 */
	public function reorder_planner(): ReorderPlanner {
		return $this->typed( 'reorder_planner', ReorderPlanner::class );
	}

	/**
	 * Returns the reorder action.
	 *
	 * @since 0.12.0
	 * @return Reorder
	 */
	public function reorder(): Reorder {
		return $this->typed( 'reorder', Reorder::class );
	}

	/**
	 * Returns the reorder REST controller.
	 *
	 * @since 0.12.0
	 * @return ReorderController
	 */
	public function reorder_controller(): ReorderController {
		return $this->typed( 'reorder_controller', ReorderController::class );
	}

	/**
	 * Returns the reorder reconciliation view.
	 *
	 * @since 0.12.0
	 * @return ReorderView
	 */
	public function reorder_view(): ReorderView {
		return $this->typed( 'reorder_view', ReorderView::class );
	}

	/**
	 * Returns the invoice-plugin detector.
	 *
	 * @since 0.13.0
	 * @return Detector
	 */
	public function invoice_detector(): Detector {
		return $this->typed( 'invoice_detector', Detector::class );
	}

	/**
	 * Returns the invoice-access action.
	 *
	 * @since 0.13.0
	 * @return Invoice
	 */
	public function invoice(): Invoice {
		return $this->typed( 'invoice', Invoice::class );
	}

	/**
	 * Returns the help-context builder.
	 *
	 * @since 0.13.0
	 * @return HelpContextBuilder
	 */
	public function help_context_builder(): HelpContextBuilder {
		return $this->typed( 'help_context_builder', HelpContextBuilder::class );
	}

	/**
	 * Returns the contextual help action.
	 *
	 * @since 0.13.0
	 * @return Help
	 */
	public function help(): Help {
		return $this->typed( 'help', Help::class );
	}

	/**
	 * Returns the help REST controller.
	 *
	 * @since 0.13.0
	 * @return HelpController
	 */
	public function help_controller(): HelpController {
		return $this->typed( 'help_controller', HelpController::class );
	}

	/**
	 * Returns the help form's view.
	 *
	 * @since 0.13.0
	 * @return HelpView
	 */
	public function help_view(): HelpView {
		return $this->typed( 'help_view', HelpView::class );
	}

	/**
	 * Returns the requests REST controller.
	 *
	 * @since 0.8.0
	 * @return RequestsController
	 */
	public function requests_controller(): RequestsController {
		return $this->typed( 'requests_controller', RequestsController::class );
	}

	/**
	 * Returns the request-modal renderer.
	 *
	 * @since 0.8.0
	 * @return RequestModalRenderer
	 */
	public function request_modal_renderer(): RequestModalRenderer {
		return $this->typed( 'request_modal_renderer', RequestModalRenderer::class );
	}

	/**
	 * Returns the admin menu.
	 *
	 * @since 0.9.0
	 * @return Menu
	 */
	public function menu(): Menu {
		return $this->typed( 'menu', Menu::class );
	}

	/**
	 * Returns the order-edit metabox.
	 *
	 * @since 0.9.0
	 * @return OrderMetabox
	 */
	public function order_metabox(): OrderMetabox {
		return $this->typed( 'order_metabox', OrderMetabox::class );
	}

	/**
	 * Returns the admin approve/decline handler.
	 *
	 * @since 0.9.0
	 * @return RequestActionController
	 */
	public function request_action_controller(): RequestActionController {
		return $this->typed( 'request_action_controller', RequestActionController::class );
	}

	/**
	 * Returns the mailer that registers this plugin's WC_Email classes.
	 *
	 * @since 0.10.0
	 * @return Mailer
	 */
	public function mailer(): Mailer {
		return $this->typed( 'mailer', Mailer::class );
	}

	/**
	 * Returns the guest-lookup on/off gate.
	 *
	 * @since 0.11.0
	 * @return GuestAccess
	 */
	public function guest_access(): GuestAccess {
		return $this->typed( 'guest_access', GuestAccess::class );
	}

	/**
	 * Returns the order-number and billing-email matcher.
	 *
	 * @since 0.11.0
	 * @return OrderLookup
	 */
	public function order_lookup(): OrderLookup {
		return $this->typed( 'order_lookup', OrderLookup::class );
	}

	/**
	 * Returns the guest-lookup flow every lookup surface shares.
	 *
	 * @since 0.11.0
	 * @return GuestLookupService
	 */
	public function guest_lookup_service(): GuestLookupService {
		return $this->typed( 'guest_lookup_service', GuestLookupService::class );
	}

	/**
	 * Returns the lookup REST controller.
	 *
	 * @since 0.11.0
	 * @return LookupController
	 */
	public function lookup_controller(): LookupController {
		return $this->typed( 'lookup_controller', LookupController::class );
	}

	/**
	 * Returns the guest-lookup form.
	 *
	 * @since 0.11.0
	 * @return LookupForm
	 */
	public function lookup_form(): LookupForm {
		return $this->typed( 'lookup_form', LookupForm::class );
	}

	/**
	 * Returns the signed-token to cookie-context exchange.
	 *
	 * @since 0.11.0
	 * @return GuestContext
	 */
	public function guest_context(): GuestContext {
		return $this->typed( 'guest_context', GuestContext::class );
	}

	/**
	 * Returns the guest order view.
	 *
	 * @since 0.11.0
	 * @return GuestOrderView
	 */
	public function guest_order_view(): GuestOrderView {
		return $this->typed( 'guest_order_view', GuestOrderView::class );
	}

	/**
	 * Returns the stored stage map's filter layer.
	 *
	 * @since 0.14.0
	 * @return StageMapConfig
	 */
	public function stage_map_config(): StageMapConfig {
		return $this->typed( 'stage_map_config', StageMapConfig::class );
	}

	/**
	 * Returns the admin health panel.
	 *
	 * @since 0.14.0
	 * @return HealthPanel
	 */
	public function health_panel(): HealthPanel {
		return $this->typed( 'health_panel', HealthPanel::class );
	}

	/**
	 * Returns the settings screen.
	 *
	 * @since 0.14.0
	 * @return SettingsPage
	 */
	public function settings_page(): SettingsPage {
		return $this->typed( 'settings_page', SettingsPage::class );
	}

	/**
	 * Returns the wizard's order-page preview.
	 *
	 * @since 0.14.0
	 * @return WizardPreview
	 */
	public function wizard_preview(): WizardPreview {
		return $this->typed( 'wizard_preview', WizardPreview::class );
	}

	/**
	 * Returns the wizard's question bodies.
	 *
	 * @since 0.14.0
	 * @return WizardSteps
	 */
	public function wizard_steps(): WizardSteps {
		return $this->typed( 'wizard_steps', WizardSteps::class );
	}

	/**
	 * Returns the wizard's screen chrome.
	 *
	 * @since 0.14.0
	 * @return WizardScreen
	 */
	public function wizard_screen(): WizardScreen {
		return $this->typed( 'wizard_screen', WizardScreen::class );
	}

	/**
	 * Returns the setup wizard.
	 *
	 * @since 0.14.0
	 * @return Wizard
	 */
	public function wizard(): Wizard {
		return $this->typed( 'wizard', Wizard::class );
	}

	/**
	 * Returns the admin asset loader.
	 *
	 * @since 0.14.0
	 * @return AdminAssets
	 */
	public function admin_assets(): AdminAssets {
		return $this->typed( 'admin_assets', AdminAssets::class );
	}

	/**
	 * Returns the admin notice.
	 *
	 * @since 0.14.0
	 * @return Notices
	 */
	public function notices(): Notices {
		return $this->typed( 'notices', Notices::class );
	}

	/**
	 * Returns the page-cache compatibility layer.
	 *
	 * @since 0.11.0
	 * @return PageCache
	 */
	public function page_cache(): PageCache {
		return $this->typed( 'page_cache', PageCache::class );
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

		// A store's invoice plugin can only start or stop existing when a plugin
		// is activated or deactivated, which is also the only moment the
		// cached detection can be wrong. Both hooks are admin-only, so this
		// costs nothing on a storefront request.
		add_action( 'activated_plugin', array( $this, 'forget_invoice_detection' ) );
		add_action( 'deactivated_plugin', array( $this, 'forget_invoice_detection' ) );

		add_action( Activator::CLEANUP_HOOK, array( $this, 'run_cleanup' ) );
		add_action( Activator::DIGEST_HOOK, array( $this, 'run_digest' ) );

		add_action( 'woocommerce_order_status_changed', array( $this, 'record_transition' ), 10, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'resync_eta_on_status_change' ), 10, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'reconcile_pending_cancellation' ), 10, 4 );

		// Shipping-line changes: the admin order editor's bulk save
		// (wc-admin-functions.php) and the per-item CRUD hooks new/updated
		// shipping items fire through WC_Order_Item_Type_Data_Store, covering
		// the admin UI, the order being placed in the first place, and any
		// programmatic edit that goes through save().
		add_action( 'woocommerce_saved_order_items', array( $this, 'resync_eta_for_order_id' ) );
		add_action( 'woocommerce_new_order_item', array( $this, 'resync_eta_for_shipping_item' ), 10, 2 );
		add_action( 'woocommerce_update_order_item', array( $this, 'resync_eta_for_shipping_item' ), 10, 2 );

		add_action( 'init', array( $this, 'register_rendering' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Not inside register_rendering()'s frontend branch: a guest who
		// exchanged their token on a page view then acts on the order over
		// REST, where is_admin() is false but template_redirect never fires, so
		// the identity filter has to be live in every context.
		$this->guest_context()->register();

		// Unconditional, unlike register_rendering()'s admin/frontend split:
		// WooCommerce builds its email registry on every request that might
		// send mail, including cron and REST ones, neither of which is_admin().
		$this->mailer()->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pph cleanup', new CleanupCommand( $this->sweeper() ) );
			\WP_CLI::add_command( 'pph backfill-timeline', new BackfillCommand( $this->transition_recorder(), $this->stage_map() ) );
		}

		// Registered unconditionally and early: a stored stage map is what the
		// timeline means on this store, and it has to be in effect for the
		// storefront, the admin queue, the emails and WP-CLI alike.
		$this->stage_map_config()->register();

		// Core's own actions fill the registry before pph_loaded fires, so
		// anything hooking that action — Pro, a filter-driven extension — sees
		// core's actions already registered rather than racing them.
		$this->cancel()->register( $this->action_registry() );
		$this->reorder()->register( $this->action_registry() );
		$this->invoice()->register( $this->action_registry() );
		$this->help()->register( $this->action_registry() );

		/**
		 * Fires once core has wired itself, with the service container.
		 *
		 * The single entry point for edition code and the earliest moment at
		 * which every service and every extension point exists. Core registers
		 * the points; whatever attaches here fills them.
		 *
		 * @since 0.5.0
		 *
		 * @param Plugin $plugin The service container.
		 */
		do_action( 'pph_loaded', $this );
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
	 * Resyncs an order's cached estimated-delivery range when its status changes.
	 *
	 * A range is a promise made at one point in time; it must not silently
	 * change because the order moved to a new status, but it does need
	 * recomputing so a later read reflects anything about the order that
	 * changed alongside the status — a cancelled or refunded order, for one,
	 * should stop showing an estimate on its next render. Deliberately a
	 * write here rather than on a read path: `woocommerce_order_status_changed`
	 * is never fired from a GET request.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved away from.
	 * @param string $to       Status moved to.
	 * @param mixed  $order    Order object as passed by WooCommerce.
	 * @return void
	 */
	public function resync_eta_on_status_change( $order_id, $from, $to, $order = null ): void {
		unset( $from, $to );

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}

		if ( $order instanceof \WC_Order ) {
			$this->estimated_delivery()->sync( $order );
		}
	}

	/**
	 * Closes a stale pending cancellation request when its order reaches
	 * `cancelled` through any route other than this plugin's own approval —
	 * a customer's own one-click cancel, a manual status change on the Orders
	 * screen, or another plugin.
	 *
	 * `Admin\RequestActionController::approve()` resolves the request row
	 * *before* transitioning the order for exactly this reason: by the time
	 * that transition fires this same hook, `pending_for_order()` below
	 * already finds nothing, so an approval never reconciles itself.
	 *
	 * @since 0.9.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved away from, unused.
	 * @param string $to       Status moved to.
	 * @param mixed  $order    Order object as passed by WooCommerce.
	 * @return void
	 */
	public function reconcile_pending_cancellation( $order_id, $from, $to, $order = null ): void {
		unset( $from );

		if ( 'cancelled' !== $to ) {
			return;
		}

		$pending = $this->requests()->pending_for_order( (int) $order_id, Request::TYPE_CANCELLATION );

		if ( null === $pending ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}

		$this->request_service()->complete(
			$pending,
			$order instanceof \WC_Order ? $order : null,
			get_current_user_id(),
			RequestService::reconciliation_note()
		);
	}

	/**
	 * Resyncs a cached estimated-delivery range after the admin order editor saves.
	 *
	 * Callback for `woocommerce_saved_order_items`, which fires once per bulk
	 * save regardless of which lines changed — cheap enough to run
	 * unconditionally rather than inspect the batch for a shipping line.
	 *
	 * @since 0.5.0
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $items    Raw posted item data, unused.
	 * @return void
	 */
	public function resync_eta_for_order_id( $order_id, $items = null ): void {
		unset( $items );

		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->estimated_delivery()->sync( $order );
		}
	}

	/**
	 * Resyncs a cached estimated-delivery range when a shipping line is written.
	 *
	 * Callback for the generic per-item CRUD hooks, which fire for every order
	 * item type — including the shipping line WooCommerce creates as part of
	 * placing the order in the first place, which is what gets a range cached
	 * before the customer ever looks at the order. Only a shipping item
	 * changing can change the estimate, so anything else is ignored without
	 * loading the order it belongs to.
	 *
	 * @since 0.5.0
	 *
	 * @param int   $item_id Order item id, unused.
	 * @param mixed $item    Order item object, as passed by WooCommerce.
	 * @return void
	 */
	public function resync_eta_for_shipping_item( $item_id, $item = null ): void {
		unset( $item_id );

		if ( ! $item instanceof \WC_Order_Item_Shipping ) {
			return;
		}

		$order = wc_get_order( $item->get_order_id() );

		if ( $order instanceof \WC_Order ) {
			$this->estimated_delivery()->sync( $order );
		}
	}

	/**
	 * Wires the rendering surfaces.
	 *
	 * Deferred to `init` because blocks and shortcodes cannot be registered
	 * earlier, and split by context because a request that renders no storefront
	 * has no reason to build a renderer or a template loader.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register_rendering(): void {
		// Registered even before setup completes, and in every context: the
		// block editor renders over REST, where is_admin() is false, and an
		// *unregistered* shortcode prints its own raw text at customers, which
		// is worse than the nothing an unconfigured store is supposed to show.
		// Both render callbacks are gated instead — see
		// Shortcodes::render_for_current_user() and Security\GuestAccess.
		$this->blocks()->register();
		$this->shortcodes()->register();

		if ( is_admin() ) {
			$this->conflict_scanner()->register();
			$this->menu()->register();
			$this->order_metabox()->register();
			$this->request_action_controller()->register();
			$this->settings_page()->register();
			$this->wizard()->register();
			$this->notices()->register();
			$this->admin_assets()->register();

			return;
		}

		// The hard requirement of docs/MILESTONE-PROMPTS.md M14: nothing this
		// plugin draws reaches a customer's page until the merchant has been
		// through the wizard. Enforced here, once, by not wiring the storefront
		// at all — rather than by every render path remembering to ask.
		if ( ! SetupState::is_complete() ) {
			return;
		}

		$this->renderer()->register();
		$this->actions_renderer()->register();
		$this->lookup_form()->register();
		$this->assets()->register();
		$this->template_replacer()->register();
		$this->request_modal_renderer()->register();
		$this->reorder_view()->register();
		$this->help_view()->register();
		$this->guest_order_view()->register();
		$this->page_cache()->register();
	}

	/**
	 * Registers this plugin's REST routes.
	 *
	 * @since 0.8.0
	 * @return void
	 */
	public function register_rest_routes(): void {
		// Same gate as the storefront, for the same reason and one more: an
		// unconfigured store must not expose customer-facing mutation
		// endpoints either. A button that is not drawn is not a control if the
		// route behind it answers anyway.
		if ( ! SetupState::is_complete() ) {
			return;
		}

		$this->requests_controller()->register_routes();
		$this->lookup_controller()->register_routes();
		$this->reorder_controller()->register_routes();
		$this->help_controller()->register_routes();
	}

	/**
	 * Drops the cached invoice-plugin detection.
	 *
	 * @since 0.13.0
	 *
	 * @return void
	 */
	public function forget_invoice_detection(): void {
		$this->invoice_detector()->forget();
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
	 * Sends the opt-in admin digest, when enabled and there is something to
	 * report. A no-op on every store that has not turned it on.
	 *
	 * @since 0.10.0
	 * @return void
	 */
	public function run_digest(): void {
		// WooCommerce includes `class-wc-email.php` inside its own mailer's
		// boot, and nowhere else. On a cron request nothing else will have
		// asked for it, so constructing our digest first would autoload a
		// subclass of a class that does not exist yet — see
		// `Emails\EmailSettings`. This also means our own emails are
		// registered by the time the digest looks for itself.
		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
		}

		$this->mailer()->admin_digest()->maybe_send();
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
