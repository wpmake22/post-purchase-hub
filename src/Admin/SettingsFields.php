<?php
/**
 * Every setting this plugin has, and which tab it lives on.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\Uninstaller;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * The declaration every other part of the settings screen reads.
 *
 * One field per behaviour that already exists in this plugin, and not one more:
 * "no setting without a user story" (docs/MILESTONE-PROMPTS.md M14). Every key
 * here is a key some service was already reading from `wpmphub_settings` before
 * this milestone — `Actions\Cancel`, `Timeline\EstimatedDelivery`,
 * `Security\GuestAccess`, `Security\TokenService`,
 * `Frontend\TemplateReplacer`, `Requests\RetentionSweeper`,
 * `Install\Uninstaller` — plus the two the wizard itself introduces (the stage
 * map and which actions are on). Nothing on this screen is a setting the
 * codebase does not act on.
 *
 * Fields are data, not markup and not behaviour: `SettingsPage` renders them,
 * `SettingsSanitizer` cleans them by `type`, and the defaults here are the
 * same constants the reading services fall back to, so an unsaved store and a
 * saved-with-defaults store behave identically.
 *
 * @since 0.14.0
 */
final class SettingsFields {

	/**
	 * The option every field is stored in.
	 *
	 * @var string
	 */
	public const OPTION = 'wpmphub_settings';

	/**
	 * Tab slugs, in the order docs/MILESTONE-PROMPTS.md M14 fixes them.
	 *
	 * @var string[]
	 */
	public const TABS = array( 'general', 'timeline', 'actions', 'guest', 'emails', 'advanced' );

	/**
	 * Human labels for the tabs.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, string>
	 */
	public static function tab_labels(): array {
		return array(
			'general'  => __( 'General', 'wpmake-post-purchase-hub' ),
			'timeline' => __( 'Timeline', 'wpmake-post-purchase-hub' ),
			'actions'  => __( 'Actions', 'wpmake-post-purchase-hub' ),
			'guest'    => __( 'Guest Access', 'wpmake-post-purchase-hub' ),
			'emails'   => __( 'Emails', 'wpmake-post-purchase-hub' ),
			'advanced' => __( 'Advanced', 'wpmake-post-purchase-hub' ),
		);
	}

	/**
	 * Whether a slug is one of this screen's tabs.
	 *
	 * @since 0.14.0
	 *
	 * @param string $tab Candidate tab slug.
	 * @return bool
	 */
	public static function is_tab( string $tab ): bool {
		return in_array( $tab, self::TABS, true );
	}

	/**
	 * Every field, keyed by settings key.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array_merge(
			self::general(),
			self::timeline(),
			self::actions(),
			self::guest(),
			self::emails(),
			self::advanced()
		);
	}

	/**
	 * The fields on one tab, in declaration order.
	 *
	 * @since 0.14.0
	 *
	 * @param string $tab Tab slug.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_tab( string $tab ): array {
		return array_filter(
			self::all(),
			static function ( array $field ) use ( $tab ): bool {
				return $tab === $field['tab'];
			}
		);
	}

	/**
	 * One field's declaration, or null when the key is not ours.
	 *
	 * @since 0.14.0
	 *
	 * @param string $key Settings key.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		$fields = self::all();

		return $fields[ $key ] ?? null;
	}

	/**
	 * The defaults every field falls back to.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::all() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * General: what the plugin shows, and where.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function general(): array {
		return array(
			TemplateReplacer::SETTING => array(
				'tab'     => 'general',
				'type'    => 'choice',
				'label'   => __( 'Order page display', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Additive adds this plugin\'s sections to your theme\'s order pages and is the safe default. Full replacement takes over the order page layout, which every theme styles differently.', 'wpmake-post-purchase-hub' ),
				'default' => TemplateReplacer::MODE_ADDITIVE,
				'choices' => array(
					TemplateReplacer::MODE_ADDITIVE    => __( 'Additive — add to my theme\'s pages (recommended)', 'wpmake-post-purchase-hub' ),
					TemplateReplacer::MODE_REPLACEMENT => __( 'Full replacement — use this plugin\'s order page', 'wpmake-post-purchase-hub' ),
				),
				'confirm' => array(
					'value'   => TemplateReplacer::MODE_REPLACEMENT,
					'message' => __( 'Full replacement changes how your order pages look. Your theme\'s own order-page styling will no longer apply. Continue?', 'wpmake-post-purchase-hub' ),
				),
			),
		);
	}

	/**
	 * Timeline: the stage map and the delivery estimate.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function timeline(): array {
		return array(
			StageMapConfig::MAP_SETTING                   => array(
				'tab'     => 'timeline',
				'type'    => 'status_map',
				'label'   => __( 'Status stages', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Which timeline stage each of your order statuses lands on. A status left unassigned contributes nothing to the customer\'s timeline, which is how an internal status stays private.', 'wpmake-post-purchase-hub' ),
				'default' => array(),
			),
			EstimatedDelivery::HANDLING_SETTING           => array(
				'tab'     => 'timeline',
				'type'    => 'days',
				'label'   => __( 'Handling time', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Business days between an order being paid for and it leaving you. Used to work out the delivery estimate.', 'wpmake-post-purchase-hub' ),
				'min'     => 0,
				'max'     => 60,
				'default' => EstimatedDelivery::DEFAULT_HANDLING_DAYS,
			),
			EstimatedDelivery::HANDLING_OVERRIDES_SETTING => array(
				'tab'     => 'timeline',
				'type'    => 'method_days',
				'label'   => __( 'Handling time per shipping method', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Overrides the handling time above for one shipping method. Leave blank to use the default.', 'wpmake-post-purchase-hub' ),
				'default' => array(),
			),
			EstimatedDelivery::TRANSIT_SETTING            => array(
				'tab'     => 'timeline',
				'type'    => 'method_range',
				'label'   => __( 'Transit time per shipping method', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Business days in transit, as a range. A shipping method with no transit time configured shows no delivery estimate at all — an honest blank rather than a guess.', 'wpmake-post-purchase-hub' ),
				'default' => array(),
			),
			EstimatedDelivery::WEEKEND_SETTING            => array(
				'tab'     => 'timeline',
				'type'    => 'weekdays',
				'label'   => __( 'Non-working days', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Days of the week you do not ship on. Excluded from every estimate.', 'wpmake-post-purchase-hub' ),
				'default' => array( 0, 6 ),
			),
			EstimatedDelivery::HOLIDAYS_SETTING           => array(
				'tab'     => 'timeline',
				'type'    => 'dates',
				'label'   => __( 'Holidays', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'One date per line, as YYYY-MM-DD. Excluded from every estimate.', 'wpmake-post-purchase-hub' ),
				'default' => array(),
			),
		);
	}

	/**
	 * Actions: which self-service actions customers get, and on what terms.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function actions(): array {
		return array(
			ActionAvailability::SETTING   => array(
				'tab'     => 'actions',
				'type'    => 'action_toggles',
				'label'   => __( 'Available actions', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Turning an action off removes it from the customer\'s order pages and refuses it at the API as well, so a hidden button cannot be submitted anyway.', 'wpmake-post-purchase-hub' ),
				'default' => ActionAvailability::DEFAULTS,
			),
			Cancel::STATUSES_SETTING      => array(
				'tab'     => 'actions',
				'type'    => 'statuses',
				'label'   => __( 'Cancellable statuses', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Which statuses a customer may request cancellation from. A request is never an automatic cancellation: you approve it.', 'wpmake-post-purchase-hub' ),
				'default' => Cancel::DEFAULT_STATUSES,
			),
			Cancel::CAP_SETTING           => array(
				'tab'     => 'actions',
				'type'    => 'positive_int',
				'label'   => __( 'Cancellation requests per order', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'How many times one order may ever be the subject of a cancellation request.', 'wpmake-post-purchase-hub' ),
				'min'     => 1,
				'max'     => 50,
				'default' => Cancel::DEFAULT_CAP,
			),
			Cancel::COOLDOWN_SETTING      => array(
				'tab'     => 'actions',
				'type'    => 'hours',
				'label'   => __( 'Wait between requests', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Hours a customer must wait before asking again about the same order.', 'wpmake-post-purchase-hub' ),
				'min'     => 0,
				'max'     => 720,
				'default' => Cancel::DEFAULT_COOLDOWN_HOURS,
			),
			Cancel::RESPONSE_TIME_SETTING => array(
				'tab'     => 'actions',
				'type'    => 'positive_int',
				'label'   => __( 'Response time you promise', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Hours. Shown to the customer when they raise a request, and in the confirmation email. Promise what you can keep.', 'wpmake-post-purchase-hub' ),
				'min'     => 1,
				'max'     => 720,
				'default' => Cancel::DEFAULT_RESPONSE_TIME_HOURS,
			),
			Cancel::RESTOCK_SETTING       => array(
				'tab'     => 'actions',
				'type'    => 'bool',
				'label'   => __( 'Restock on approval', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Return the items to stock when you approve a cancellation. This plugin never issues a refund — you do that in WooCommerce, one click from the notification.', 'wpmake-post-purchase-hub' ),
				'default' => Cancel::DEFAULT_RESTOCK_ON_APPROVE,
			),
		);
	}

	/**
	 * Guest Access: the one feature that is off until it is acknowledged.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function guest(): array {
		return array(
			GuestAccess::ENABLED_SETTING      => array(
				'tab'     => 'guest',
				'type'    => 'bool',
				'label'   => __( 'Guest order lookup', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Lets a customer without an account find their order with its number and the billing email on it. Off until you acknowledge the note below.', 'wpmake-post-purchase-hub' ),
				'default' => false,
				'confirm' => array(
					'value'   => true,
					'message' => __( 'Guest lookup makes a public form that accepts an order number and an email address. It is rate limited and reveals nothing without both, but it is a public endpoint. Continue?', 'wpmake-post-purchase-hub' ),
				),
			),
			GuestAccess::ACKNOWLEDGED_SETTING => array(
				'tab'     => 'guest',
				'type'    => 'bool',
				'label'   => __( 'Security acknowledgement', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'I understand that guest lookup adds a public endpoint, that it emails a secure link to the address already on the order and never to a submitted one, and that it can be switched off here at any time.', 'wpmake-post-purchase-hub' ),
				'default' => false,
			),
			TokenService::TTL_SETTING         => array(
				'tab'     => 'guest',
				'type'    => 'positive_int',
				'label'   => __( 'Secure link lifetime', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Days a secure order link keeps working. Shorter is safer; long enough that a customer coming back to an old email is not stranded.', 'wpmake-post-purchase-hub' ),
				'min'     => 1,
				'max'     => TokenService::MAX_TTL_DAYS,
				'default' => TokenService::DEFAULT_TTL_DAYS,
			),
		);
	}

	/**
	 * Emails: a signpost, not a second settings screen.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function emails(): array {
		return array();
	}

	/**
	 * Advanced: retention, and the uninstall decision.
	 *
	 * @since 0.14.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function advanced(): array {
		return array(
			RetentionSweeper::RETENTION_SETTING => array(
				'tab'     => 'advanced',
				'type'    => 'days',
				'label'   => __( 'Keep resolved requests for', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Days. Resolved requests older than this are deleted by the daily cleanup, so this table does not grow forever. Zero keeps everything.', 'wpmake-post-purchase-hub' ),
				'min'     => 0,
				'max'     => RetentionSweeper::MAX_RETENTION_DAYS,
				'default' => RetentionSweeper::DEFAULT_RETENTION_DAYS,
			),
			Uninstaller::SETTING                => array(
				'tab'     => 'advanced',
				'type'    => 'bool',
				'label'   => __( 'Delete all data when uninstalling', 'wpmake-post-purchase-hub' ),
				'help'    => __( 'Off by default. When on, deleting this plugin also deletes its tables, its settings and the timeline data on your orders. There is no undo.', 'wpmake-post-purchase-hub' ),
				'default' => false,
				'confirm' => array(
					'value'   => true,
					'message' => __( 'This means deleting the plugin permanently deletes its request history and the timeline data on your orders. There is no undo. Continue?', 'wpmake-post-purchase-hub' ),
				),
			),
		);
	}
}
