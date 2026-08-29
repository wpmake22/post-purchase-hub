<?php
/**
 * REST controller behind the setup wizard.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\SettingsPage;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Install\SetupSteps;
use PostPurchaseHub\Security\Sanitizer;

/**
 * `wpmphub/v1/setup` — the wizard's whole server side.
 *
 * The wizard is the one screen that must work before setup is complete, so
 * these routes register outside the gate every other route sits behind
 * (`Plugin::register_rest_routes()`). That makes the permission callback the
 * only thing standing in front of them, which is why it is the same capability
 * the settings screen requires and why it is declared on every route rather
 * than on the namespace: `__return_true` is forbidden here (CLAUDE.md hard rule
 * 3) and an unauthenticated wizard would be a settings-writing endpoint open to
 * the internet.
 *
 * Every write is a POST and every GET is a read (hard rule 4). A step's answers
 * go to `SetupState::remember_draft()` — never to `wpmphub_settings` — so a
 * merchant who closes the tab has changed nothing about their store. `finish()`
 * is the single write of the real option, and `SetupState::complete()`
 * immediately after it is the one moment the storefront starts rendering.
 *
 * Each mutation answers with the whole wizard state rather than an
 * acknowledgement, so the React app never has to guess what the server now
 * thinks the current step is — including after an answer on the welcome screen
 * removes screens further along.
 *
 * @since 0.15.0
 */
final class SetupController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'wpmphub/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	public const ROUTE = '/setup';

	/**
	 * Capability required to run setup.
	 *
	 * @var string
	 */
	public const CAPABILITY = SettingsPage::CAPABILITY;

	/**
	 * Constructor.
	 *
	 * @since 0.15.0
	 *
	 * @param SetupContext $context Reference data only PHP knows.
	 */
	public function __construct( private SetupContext $context ) {}

	/**
	 * Registers every route.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public function register_routes(): void {
		$this->read( '', 'get_state' );
		$this->read( '/context', 'get_context' );

		$this->write( '/welcome', 'save_welcome', SetupArgs::welcome() );
		$this->write( '/statuses', 'save_statuses', SetupArgs::statuses() );
		$this->write( '/delivery', 'save_delivery', SetupArgs::delivery() );
		$this->write( '/tracking', 'acknowledge_tracking', array() );
		$this->write( '/actions', 'save_actions', SetupArgs::actions() );
		$this->write( '/display', 'save_display', SetupArgs::display() );
		$this->write( '/skip', 'skip', SetupArgs::skip() );
		$this->write( '/finish', 'finish', array() );
	}

	/**
	 * Registers one GET route.
	 *
	 * @since 0.15.0
	 *
	 * @param string $suffix   Path after the route base.
	 * @param string $callback Method on this class.
	 * @return void
	 */
	private function read( string $suffix, string $callback ): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . $suffix,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Registers one POST route.
	 *
	 * @since 0.15.0
	 *
	 * @param string                              $suffix   Path after the route base.
	 * @param string                              $callback Method on this class.
	 * @param array<string, array<string, mixed>> $args    Schema for this route's fields.
	 * @return void
	 */
	private function write( string $suffix, string $callback, array $args ): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . $suffix,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Whether this user may run setup.
	 *
	 * Opens by marking the response uncacheable, before the decision rather
	 * than after it, so a denial is not cached either — these routes answer
	 * with the store's settings and its order statuses (docs/SPEC.md Phase 8).
	 *
	 * @since 0.15.0
	 *
	 * @return bool|\WP_Error
	 */
	public function authorise() {
		Sanitizer::nocache();

		if ( current_user_can( self::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'wpmphub_setup_forbidden',
			__( 'You do not have permission to run setup.', 'wpmake-post-purchase-hub' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * `GET /setup` — where the merchant is, and what is left.
	 *
	 * @since 0.15.0
	 * @return \WP_REST_Response
	 */
	public function get_state(): \WP_REST_Response {
		return new \WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * `GET /setup/context` — the reference data every screen draws from.
	 *
	 * @since 0.15.0
	 * @return \WP_REST_Response
	 */
	public function get_context(): \WP_REST_Response {
		$payload = $this->context->payload();

		$payload['live_status_map'] = $this->context->live_status_map();
		$payload['path_choices']    = SetupSteps::path_choices();

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * `POST /setup/welcome` — the answer that decides which screens follow.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_welcome( \WP_REST_Request $request ): \WP_REST_Response {
		$path = (string) $request->get_param( 'path' );

		SetupState::remember_path( $path );

		return $this->advance( SetupSteps::WELCOME );
	}

	/**
	 * `POST /setup/statuses` — the stage map.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_statuses( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->record( SetupSteps::STATUSES, $request );
	}

	/**
	 * `POST /setup/delivery` — handling time, globally and per method.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_delivery( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->record( SetupSteps::DELIVERY, $request );
	}

	/**
	 * `POST /setup/tracking` — nothing to store, so nothing is stored.
	 *
	 * The screen tells a merchant what tracking data their store has. It is
	 * kept as a route anyway so the client advances through one code path
	 * rather than special-casing the one screen that asks nothing.
	 *
	 * @since 0.15.0
	 * @return \WP_REST_Response
	 */
	public function acknowledge_tracking(): \WP_REST_Response {
		return $this->advance( SetupSteps::TRACKING );
	}

	/**
	 * `POST /setup/actions` — which self-service actions to switch on.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_actions( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->record( SetupSteps::ACTIONS, $request );
	}

	/**
	 * `POST /setup/display` — additive or full replacement.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_display( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->record( SetupSteps::DISPLAY, $request );
	}

	/**
	 * `POST /setup/skip` — move on without answering.
	 *
	 * A skipped step records nothing, which is exactly how its settings keep
	 * their shipped defaults. Offered on every step because a merchant who
	 * cannot answer one question in two minutes must still be able to reach the
	 * end: a default they can change later beats an abandoned setup that leaves
	 * the storefront dark.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function skip( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->advance( (string) $request->get_param( 'step' ) );
	}

	/**
	 * `POST /setup/finish` — the one write, and the moment the store goes live.
	 *
	 * Guest access is deliberately absent from every step and so from every
	 * draft: `Security\GuestAccess` needs an acknowledgement a wizard should not
	 * be collecting in passing, so it stays off until a merchant turns it on
	 * deliberately on the settings screen (CLAUDE.md hard rule 15).
	 *
	 * @since 0.15.0
	 * @return \WP_REST_Response
	 */
	public function finish(): \WP_REST_Response {
		$stored = get_option( SettingsFields::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( SettingsFields::OPTION, array_merge( $stored, SetupState::draft() ), false );

		SetupState::complete();

		return new \WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * Stores one step's answers as drafts and moves the merchant on.
	 *
	 * @since 0.15.0
	 *
	 * @param string           $step    Step being answered.
	 * @param \WP_REST_Request $request Request, already through the args schema.
	 * @return \WP_REST_Response
	 */
	private function record( string $step, \WP_REST_Request $request ): \WP_REST_Response {
		$map   = SetupArgs::parameter_map();
		$draft = array();

		foreach ( SetupSteps::fields( $step ) as $key ) {
			$parameter = array_search( $key, $map, true );

			if ( ! is_string( $parameter ) ) {
				continue;
			}

			$value = $request->get_param( $parameter );

			// An absent parameter is a question the merchant did not answer on a
			// screen they did answer — leave the earlier draft, and the default
			// behind it, alone rather than overwriting either with a null.
			if ( null !== $value ) {
				$draft[ $key ] = $value;
			}
		}

		if ( array() !== $draft ) {
			SetupState::remember_draft( $draft );
		}

		return $this->advance( $step );
	}

	/**
	 * Records the next step and answers with the new state.
	 *
	 * @since 0.15.0
	 *
	 * @param string $step Step just left.
	 * @return \WP_REST_Response
	 */
	private function advance( string $step ): \WP_REST_Response {
		SetupState::remember_step( SetupSteps::next( SetupState::path(), $step ) );

		return new \WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * The wizard's state as the client models it.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$path  = SetupState::path();
		$steps = array();

		foreach ( SetupSteps::for_path( $path ) as $index => $step ) {
			$steps[] = array(
				'id'     => $step,
				'label'  => SetupSteps::labels()[ $step ],
				'number' => $index + 1,
			);
		}

		return array(
			'path'         => $path,
			'current_step' => SetupState::current_step(),
			'steps'        => $steps,
			'draft'        => SetupState::draft(),
			'settings'     => SettingsPage::stored(),
			'completed'    => SetupState::is_complete(),
			'settings_url' => SettingsPage::url(),
		);
	}
}
