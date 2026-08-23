<?php
/**
 * The settings screen's left rail.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

/**
 * Search, then one row per tab.
 *
 * A vertical rail rather than the horizontal tab strip this replaced: six tabs
 * with room for a label and an icon each, reading as a list of places rather
 * than as a row of words competing for width. The search box above it covers
 * the case the rail cannot — finding a setting whose tab you have forgotten,
 * which is most visits, because a merchant comes back to this screen about
 * twice a year.
 *
 * The rail lists tabs and nothing else. An earlier version nested each tab's
 * cards underneath it as a table of contents; it was dropped because it turned
 * a six-item list into a tree, to reach anchors that were one scroll away on a
 * pane rarely longer than a screen.
 *
 * @since 0.15.0
 */
final class SettingsSidebar {

	/**
	 * Draws the rail.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	public function render( string $tab ): void {
		echo '<div class="pph-settings__sidebar" data-pph-settings-sidebar>';

		self::render_search();

		echo '<nav class="pph-settings__nav" data-pph-settings-tabs>';

		foreach ( SettingsFields::tab_labels() as $slug => $label ) {
			self::render_nav_item( (string) $slug, (string) $label, $slug === $tab );
		}

		echo '</nav></div>';
	}

	/**
	 * The search box.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	private static function render_search(): void {
		echo '<div class="pph-settings__search">';

		printf(
			'<label class="screen-reader-text" for="pph-settings-search">%s</label>',
			esc_html__( 'Search settings on this tab', 'post-purchase-hub' )
		);

		printf(
			'<input type="search" id="pph-settings-search" placeholder="%s" autocomplete="off" data-pph-settings-search />',
			esc_attr__( 'Search settings…', 'post-purchase-hub' )
		);

		echo wp_kses( SettingsIcons::search(), SettingsIcons::allowed_tags() );

		echo '</div>';
	}

	/**
	 * One tab in the rail.
	 *
	 * `nav-tab-active` is kept alongside our own class: it is the name every
	 * WordPress screen uses for "this one", and dropping it would break the
	 * assumption anything reading this markup is entitled to make.
	 *
	 * @since 0.15.0
	 *
	 * @param string $slug    Tab slug.
	 * @param string $label   Tab label.
	 * @param bool   $current Whether this tab is open.
	 * @return void
	 */
	private static function render_nav_item( string $slug, string $label, bool $current ): void {
		echo '<div class="pph-settings__nav-item">';

		printf(
			'<a href="%1$s" class="pph-settings__nav-link nav-tab%2$s" data-pph-settings-tab="%3$s"%4$s>%5$s<span class="pph-settings__nav-label">%6$s</span>%7$s</a>',
			esc_url( SettingsPage::tab_url( $slug ) ),
			$current ? ' nav-tab-active is-active' : '',
			esc_attr( $slug ),
			$current ? ' aria-current="page"' : '',
			wp_kses( SettingsIcons::get( $slug ), SettingsIcons::allowed_tags() ),
			esc_html( $label ),
			wp_kses( SettingsIcons::chevron(), SettingsIcons::allowed_tags() )
		);

		echo '</div>';
	}
}
