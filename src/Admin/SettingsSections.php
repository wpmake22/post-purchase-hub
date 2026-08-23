<?php
/**
 * How each settings tab is broken into cards.
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
 * The grouping that turns six long lists of fields into something readable.
 *
 * `SettingsFields` says what every setting *is*; this says which ones belong on
 * a card together and what that card is called. They are separate on purpose: a
 * field's declaration is about how it is stored and sanitised, and none of that
 * should change because a screen was rearranged.
 *
 * The grouping is also the tab's table of contents — the sidebar lists these
 * section titles under the open tab and scrolls to them — so a section is never
 * only decorative. A tab with one section still declares it, because "one card,
 * no sub-navigation" is a layout decision and not an absence of one.
 *
 * A field left out of every section here still renders: `for_tab()` sweeps the
 * remainder into a trailing card rather than silently dropping it, so adding a
 * setting and forgetting this file is a cosmetic mistake and not a missing
 * control.
 *
 * @since 0.15.0
 */
final class SettingsSections {

	/**
	 * Every tab's sections, in the order they are drawn.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<int, array{id: string, title: string, desc: string, fields: array<int, string>}>>
	 */
	private static function declared(): array {
		return array(
			'general'  => array(
				self::section(
					'display',
					__( 'Order page display', 'post-purchase-hub' ),
					__( 'Where this plugin draws on the pages your customers already visit.', 'post-purchase-hub' ),
					array( TemplateReplacer::SETTING )
				),
			),
			'timeline' => array(
				self::section(
					'stages',
					__( 'Timeline stages', 'post-purchase-hub' ),
					__( 'Which of your order statuses a customer sees, and as what.', 'post-purchase-hub' ),
					array( StageMapConfig::MAP_SETTING )
				),
				self::section(
					'estimates',
					__( 'Delivery estimates', 'post-purchase-hub' ),
					__( 'How long an order takes to leave you, and how long it spends in transit. A shipping method with no transit time shows no estimate at all.', 'post-purchase-hub' ),
					array(
						EstimatedDelivery::HANDLING_SETTING,
						EstimatedDelivery::HANDLING_OVERRIDES_SETTING,
						EstimatedDelivery::TRANSIT_SETTING,
					)
				),
				self::section(
					'calendar',
					__( 'Working days', 'post-purchase-hub' ),
					__( 'Days you do not ship on. Excluded from every estimate above.', 'post-purchase-hub' ),
					array(
						EstimatedDelivery::WEEKEND_SETTING,
						EstimatedDelivery::HOLIDAYS_SETTING,
					)
				),
			),
			'actions'  => array(
				self::section(
					'available',
					__( 'Available actions', 'post-purchase-hub' ),
					__( 'An action turned off here is removed from the customer\'s order pages and refused at the API as well.', 'post-purchase-hub' ),
					array( ActionAvailability::SETTING )
				),
				self::section(
					'cancellation',
					__( 'Cancellation requests', 'post-purchase-hub' ),
					__( 'A cancellation is always a request you approve or decline. This plugin never cancels an order by itself and never issues a refund.', 'post-purchase-hub' ),
					array(
						Cancel::STATUSES_SETTING,
						Cancel::CAP_SETTING,
						Cancel::COOLDOWN_SETTING,
						Cancel::RESPONSE_TIME_SETTING,
						Cancel::RESTOCK_SETTING,
					)
				),
			),
			'guest'    => array(
				self::section(
					'lookup',
					__( 'Guest order lookup', 'post-purchase-hub' ),
					__( 'Off until you acknowledge what it does. Both switches are required — ticking only the first saves as off.', 'post-purchase-hub' ),
					array(
						GuestAccess::ENABLED_SETTING,
						GuestAccess::ACKNOWLEDGED_SETTING,
					)
				),
				self::section(
					'links',
					__( 'Secure links', 'post-purchase-hub' ),
					__( 'The signed links emailed to the address already on the order, and how long they keep working.', 'post-purchase-hub' ),
					array( TokenService::TTL_SETTING )
				),
			),
			'advanced' => array(
				self::section(
					'retention',
					__( 'Request history', 'post-purchase-hub' ),
					__( 'The daily cleanup keeps this table from growing forever.', 'post-purchase-hub' ),
					array( RetentionSweeper::RETENTION_SETTING )
				),
				self::section(
					'uninstall',
					__( 'Uninstalling', 'post-purchase-hub' ),
					__( 'What happens to your data if this plugin is deleted.', 'post-purchase-hub' ),
					array( Uninstaller::SETTING )
				),
			),
		);
	}

	/**
	 * One tab's sections, with any field this file forgot swept into a
	 * trailing card rather than dropped.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Tab slug.
	 * @return array<int, array{id: string, title: string, desc: string, fields: array<int, string>}>
	 */
	public static function for_tab( string $tab ): array {
		$declared = self::declared()[ $tab ] ?? array();
		$known    = array_keys( SettingsFields::for_tab( $tab ) );
		$placed   = array();

		foreach ( $declared as $section ) {
			$placed = array_merge( $placed, $section['fields'] );
		}

		$sections = array();

		// Only sections that still have a field are drawn: a build without one
		// of these settings should show no empty card where it used to be.
		foreach ( $declared as $section ) {
			$section['fields'] = array_values( array_intersect( $section['fields'], $known ) );

			if ( array() !== $section['fields'] ) {
				$sections[] = $section;
			}
		}

		$orphans = array_values( array_diff( $known, $placed ) );

		if ( array() !== $orphans ) {
			$sections[] = self::section(
				'other',
				__( 'Other settings', 'post-purchase-hub' ),
				'',
				$orphans
			);
		}

		return $sections;
	}

	/**
	 * The sentence under a tab's title, saying what the tab is for.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_description( string $tab ): string {
		$descriptions = array(
			'general'  => __( 'How this plugin appears on your storefront, and whether it is working.', 'post-purchase-hub' ),
			'timeline' => __( 'What a customer sees about where their order has got to.', 'post-purchase-hub' ),
			'actions'  => __( 'What a customer can do about an order without emailing you.', 'post-purchase-hub' ),
			'guest'    => __( 'Whether a customer without an account can find their order.', 'post-purchase-hub' ),
			'emails'   => __( 'Every email this plugin sends, and where to change it.', 'post-purchase-hub' ),
			'advanced' => __( 'Data retention and what happens on uninstall.', 'post-purchase-hub' ),
		);

		return $descriptions[ $tab ] ?? '';
	}

	/**
	 * One section declaration.
	 *
	 * @since 0.15.0
	 *
	 * @param string             $id     Section slug, used as the anchor.
	 * @param string             $title  Card heading.
	 * @param string             $desc   One sentence under the heading.
	 * @param array<int, string> $fields Settings keys on this card.
	 * @return array{id: string, title: string, desc: string, fields: array<int, string>}
	 */
	private static function section( string $id, string $title, string $desc, array $fields ): array {
		return array(
			'id'     => $id,
			'title'  => $title,
			'desc'   => $desc,
			'fields' => $fields,
		);
	}
}
