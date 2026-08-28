<?php
/**
 * The wizard's step model: which screens exist, and which a store is shown.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Install;

use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMapConfig;

/**
 * One declaration of the wizard's shape, read by the REST layer and shipped to
 * the React app so both agree on what "step 3" means.
 *
 * Steps are identified by slug rather than by number, because the number is not
 * stable: the welcome screen asks what the merchant is here for and the answer
 * removes screens that would waste their time. A store that only wants to cut
 * support tickets is not asked to map its order statuses onto timeline stages;
 * a store that only wants a timeline is not walked through the action switches.
 * With numbered steps, "resume on step 3" would land somewhere different
 * depending on an answer given after the number was stored.
 *
 * Skipping a step and not being shown it are the same thing here: both leave
 * that step's settings keys out of the drafts, so the shipped defaults apply.
 * That is what makes a short path safe rather than half-configured.
 *
 * @since 0.15.0
 */
final class SetupSteps {

	/**
	 * Why the merchant installed this, and which screens follow from it.
	 *
	 * @var string
	 */
	public const WELCOME = 'welcome';

	/**
	 * The stage map.
	 *
	 * @var string
	 */
	public const STATUSES = 'statuses';

	/**
	 * Handling time, globally and per shipping method.
	 *
	 * @var string
	 */
	public const DELIVERY = 'delivery';

	/**
	 * What tracking data this store actually has. Informational.
	 *
	 * @var string
	 */
	public const TRACKING = 'tracking';

	/**
	 * Which self-service actions customers get.
	 *
	 * @var string
	 */
	public const ACTIONS = 'actions';

	/**
	 * Additive or full replacement.
	 *
	 * @var string
	 */
	public const DISPLAY = 'display';

	/**
	 * The screen that commits the drafts and opens the storefront.
	 *
	 * @var string
	 */
	public const FINISH = 'finish';

	/**
	 * Everything, in order. The default, and the recommended answer.
	 *
	 * @var string
	 */
	public const PATH_COMPLETE = 'complete';

	/**
	 * Timeline and delivery estimates; the action switches keep their defaults.
	 *
	 * @var string
	 */
	public const PATH_TIMELINE = 'timeline';

	/**
	 * Self-service actions; the timeline keeps its shipped stage map.
	 *
	 * @var string
	 */
	public const PATH_ACTIONS = 'actions';

	/**
	 * The path a store gets before it has answered the welcome screen.
	 *
	 * @var string
	 */
	public const DEFAULT_PATH = self::PATH_COMPLETE;

	/**
	 * The ordered steps of each path.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function paths(): array {
		return array(
			self::PATH_COMPLETE => array(
				self::WELCOME,
				self::STATUSES,
				self::DELIVERY,
				self::TRACKING,
				self::ACTIONS,
				self::DISPLAY,
				self::FINISH,
			),
			self::PATH_TIMELINE => array(
				self::WELCOME,
				self::STATUSES,
				self::DELIVERY,
				self::TRACKING,
				self::DISPLAY,
				self::FINISH,
			),
			self::PATH_ACTIONS  => array(
				self::WELCOME,
				self::ACTIONS,
				self::DISPLAY,
				self::FINISH,
			),
		);
	}

	/**
	 * The steps one path shows, in order.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path Path slug.
	 * @return array<int, string>
	 */
	public static function for_path( string $path ): array {
		$paths = self::paths();

		return $paths[ $path ] ?? $paths[ self::DEFAULT_PATH ];
	}

	/**
	 * The short label shown under each step's circle in the progress bar.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::WELCOME  => __( 'Welcome', 'wpmake-post-purchase-hub' ),
			self::STATUSES => __( 'Statuses', 'wpmake-post-purchase-hub' ),
			self::DELIVERY => __( 'Delivery', 'wpmake-post-purchase-hub' ),
			self::TRACKING => __( 'Tracking', 'wpmake-post-purchase-hub' ),
			self::ACTIONS  => __( 'Actions', 'wpmake-post-purchase-hub' ),
			self::DISPLAY  => __( 'Display', 'wpmake-post-purchase-hub' ),
			self::FINISH   => __( 'Finish', 'wpmake-post-purchase-hub' ),
		);
	}

	/**
	 * The choices the welcome screen offers, as the React app renders them.
	 *
	 * @since 0.15.0
	 *
	 * @return array<int, array{id: string, label: string, description: string}>
	 */
	public static function path_choices(): array {
		return array(
			array(
				'id'          => self::PATH_COMPLETE,
				'label'       => __( 'Set everything up', 'wpmake-post-purchase-hub' ),
				'description' => __( 'The full walkthrough: your order statuses, delivery estimates, tracking, what customers can do, and how it all appears. About two minutes.', 'wpmake-post-purchase-hub' ),
			),
			array(
				'id'          => self::PATH_TIMELINE,
				'label'       => __( 'Keep customers informed', 'wpmake-post-purchase-hub' ),
				'description' => __( 'Order timelines and delivery estimates only. Self-service actions keep their defaults and you can change them later.', 'wpmake-post-purchase-hub' ),
			),
			array(
				'id'          => self::PATH_ACTIONS,
				'label'       => __( 'Cut support tickets', 'wpmake-post-purchase-hub' ),
				'description' => __( 'Cancellation requests, reordering, invoices and the help form. The timeline keeps the stage map this plugin ships with.', 'wpmake-post-purchase-hub' ),
			),
		);
	}

	/**
	 * Which settings keys a step collects.
	 *
	 * A step absent from this list — welcome, tracking, finish — collects no
	 * settings at all, and that is deliberate rather than an omission: two of
	 * them are informational and one only commits what the others gathered.
	 *
	 * @since 0.15.0
	 *
	 * @param string $step Step slug.
	 * @return array<int, string>
	 */
	public static function fields( string $step ): array {
		switch ( $step ) {
			case self::STATUSES:
				return array( StageMapConfig::MAP_SETTING );
			case self::DELIVERY:
				return array(
					EstimatedDelivery::HANDLING_SETTING,
					EstimatedDelivery::HANDLING_OVERRIDES_SETTING,
				);
			case self::ACTIONS:
				return array( ActionAvailability::SETTING );
			case self::DISPLAY:
				return array( TemplateReplacer::SETTING );
			default:
				return array();
		}
	}

	/**
	 * Whether a slug is a step this wizard has.
	 *
	 * @since 0.15.0
	 *
	 * @param string $step Candidate slug.
	 * @return bool
	 */
	public static function is_step( string $step ): bool {
		return isset( self::labels()[ $step ] );
	}

	/**
	 * Whether a slug is a path this wizard offers.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path Candidate slug.
	 * @return bool
	 */
	public static function is_path( string $path ): bool {
		return isset( self::paths()[ $path ] );
	}

	/**
	 * The step after this one on this path.
	 *
	 * The last step is its own successor: the wizard ends by completing setup,
	 * never by walking off the end of the list.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path Path slug.
	 * @param string $step Current step slug.
	 * @return string
	 */
	public static function next( string $path, string $step ): string {
		$steps = self::for_path( $path );
		$index = array_search( $step, $steps, true );

		if ( false === $index ) {
			return $steps[0];
		}

		return $steps[ min( count( $steps ) - 1, (int) $index + 1 ) ];
	}

	/**
	 * The step a merchant may resume on: the one they reached, if this path
	 * still has it, and the first one otherwise.
	 *
	 * Changing the answer on the welcome screen can remove the step a merchant
	 * was previously on. Clamping here rather than in the React app means a
	 * hand-edited URL cannot land on a screen this path does not include.
	 *
	 * @since 0.15.0
	 *
	 * @param string $path Path slug.
	 * @param string $step Candidate step slug.
	 * @return string
	 */
	public static function clamp( string $path, string $step ): string {
		$steps = self::for_path( $path );

		return in_array( $step, $steps, true ) ? $step : $steps[0];
	}
}
