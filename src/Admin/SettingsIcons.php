<?php
/**
 * The tab icons, inline.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

/**
 * Six line icons, drawn from this file rather than fetched.
 *
 * Inline SVG because it is the only way to get an icon that inherits the
 * navigation's own colour as it moves between states, and because CLAUDE.md
 * hard rule 7 forbids remote assets outright — an icon font or a sprite from a
 * CDN is not an option, and a PNG per state would be four files that have to
 * agree with each other.
 *
 * `currentColor` throughout: the sidebar tints its own icons, so nothing here
 * needs to know what the active colour is.
 *
 * @since 0.15.0
 */
final class SettingsIcons {

	/**
	 * The path data of each tab's icon, on a 24×24 grid.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		'general'  => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h10M18 18h2M14 4v4M8 10v4M14 16v4',
		'timeline' => 'M12 7v5l3 2M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0',
		'actions'  => 'M9 11V6a2 2 0 1 1 4 0v5m0-2a2 2 0 1 1 4 0v2m0-1a2 2 0 1 1 4 0v5a7 7 0 0 1-7 7h-1a7 7 0 0 1-7-7v-2a2 2 0 1 1 4 0',
		'guest'    => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
		'emails'   => 'M4 6h16v12H4zM4 7l8 6 8-6',
		'advanced' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.9-1.2V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z',
	);

	/**
	 * One tab's icon as escaped, ready-to-print markup.
	 *
	 * Built here rather than stored as markup so the only variable part is a
	 * path string this file owns: nothing a merchant or another plugin can
	 * influence reaches the output.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Tab slug.
	 * @return string Empty when the tab has no icon.
	 */
	public static function get( string $tab ): string {
		if ( ! isset( self::PATHS[ $tab ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="pph-settings__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="%s"/></svg>',
			esc_attr( self::PATHS[ $tab ] )
		);
	}

	/**
	 * The chevron the sidebar puts at the end of every navigation item.
	 *
	 * @since 0.15.0
	 * @return string
	 */
	public static function chevron(): string {
		return '<svg class="pph-settings__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>';
	}

	/**
	 * The magnifier inside the search box.
	 *
	 * @since 0.15.0
	 * @return string
	 */
	public static function search(): string {
		return '<svg class="pph-settings__search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>';
	}

	/**
	 * The tags `wp_kses()` is allowed to keep in one of these icons.
	 *
	 * The icons are built from constants in this class and carry nothing a
	 * merchant or another plugin can influence, so filtering them is
	 * belt-and-braces rather than the only control — but markup printed without
	 * escaping is markup nobody checks again, and hard rule 5 does not make an
	 * exception for markup we wrote ourselves.
	 *
	 * @since 0.15.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_tags(): array {
		$attributes = array(
			'class'           => true,
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
			'd'               => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
		);

		return array(
			'svg'    => $attributes,
			'path'   => $attributes,
			'circle' => $attributes,
		);
	}

	/**
	 * The hamburger that opens the sidebar on a narrow screen.
	 *
	 * @since 0.15.0
	 * @return string
	 */
	public static function menu(): string {
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
	}
}
