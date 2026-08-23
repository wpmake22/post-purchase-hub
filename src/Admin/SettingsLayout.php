<?php
/**
 * The settings screen's chrome: sidebar, header, cards and the save bar.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Admin;

/**
 * Two panes: what you are configuring on the left, what you are changing on the
 * right.
 *
 * The screen this replaced was a horizontal tab strip over one `form-table`.
 * That shape stops working at about a dozen settings — six tabs of unlabelled
 * rows, with no way to see what a tab contains without opening it, and no way
 * to find a setting whose tab you have forgotten. A vertical rail fixes the
 * first (every tab is visible at once, with its sections listed under the open
 * one) and the search box fixes the second.
 *
 * Search is filtered in the browser rather than looked up on the server. Every
 * field on the tab is already in the page, so filtering is instant and needs no
 * endpoint, no nonce and no round trip — and a merchant typing "refund" gets an
 * honest empty state instead of a spinner.
 *
 * This class draws the shell and the cards; `SettingsSidebar` draws the rail.
 * `SettingsFields` declares, `SettingsSections` groups, `SettingsRenderer` draws
 * one control, `SettingsPage` routes and saves.
 *
 * @since 0.15.0
 */
final class SettingsLayout {

	/**
	 * Constructor.
	 *
	 * @since 0.15.0
	 *
	 * @param HealthPanel     $health  Drawn as the first card on the General tab.
	 * @param SettingsSidebar $sidebar Draws the left rail.
	 */
	public function __construct( private HealthPanel $health, private SettingsSidebar $sidebar ) {}

	/**
	 * Opens the shell and draws the sidebar.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	public function open( string $tab ): void {
		echo '<div class="wrap pph-settings" data-pph-settings>';

		printf(
			'<h1 class="screen-reader-text">%s</h1>',
			esc_html__( 'Post-Purchase Hub settings', 'post-purchase-hub' )
		);

		settings_errors();

		echo '<div class="pph-settings__shell">';

		$this->sidebar->render( $tab );

		echo '<div class="pph-settings__main">';

		$this->render_header( $tab );
	}

	/**
	 * Closes the shell.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public function close(): void {
		echo '</div></div></div>';
	}

	/**
	 * The right pane's title bar.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	private function render_header( string $tab ): void {
		$labels = SettingsFields::tab_labels();

		echo '<header class="pph-settings__header">';

		printf(
			'<button type="button" class="pph-settings__burger" data-pph-settings-burger aria-expanded="false"><span class="screen-reader-text">%1$s</span>%2$s</button>',
			esc_html__( 'Show settings navigation', 'post-purchase-hub' ),
			wp_kses( SettingsIcons::menu(), SettingsIcons::allowed_tags() )
		);

		echo '<div class="pph-settings__heading">';

		printf(
			'<h2>%1$s%2$s</h2>',
			wp_kses( SettingsIcons::get( $tab ), SettingsIcons::allowed_tags() ),
			esc_html( (string) ( $labels[ $tab ] ?? $tab ) )
		);

		$description = SettingsSections::tab_description( $tab );

		if ( '' !== $description ) {
			printf( '<p>%s</p>', esc_html( $description ) );
		}

		echo '</div></header>';
	}

	/**
	 * The cards of one tab, and the health panel where it belongs.
	 *
	 * @since 0.15.0
	 *
	 * @param string           $tab      Tab slug.
	 * @param SettingsRenderer $renderer Draws one field.
	 * @return void
	 */
	public function render_sections( string $tab, SettingsRenderer $renderer ): void {
		echo '<div class="pph-settings__cards" data-pph-settings-cards>';

		if ( 'general' === $tab ) {
			$this->health->render( self::anchor( $tab, 'status' ) );
		}

		foreach ( SettingsSections::for_tab( $tab ) as $section ) {
			$this->open_card( self::anchor( $tab, $section['id'] ), $section['title'], $section['desc'], $section['id'] );

			foreach ( $section['fields'] as $key ) {
				$field = SettingsFields::get( $key );

				if ( null !== $field ) {
					$renderer->render_row( $key, $field );
				}
			}

			self::close_card();
		}

		printf(
			'<p class="pph-settings__empty" data-pph-settings-empty hidden>%s</p>',
			esc_html__( 'No settings on this tab match your search.', 'post-purchase-hub' )
		);

		echo '</div>';
	}

	/**
	 * Opens one card.
	 *
	 * @since 0.15.0
	 *
	 * @param string $anchor  Element id, used by the sidebar's section links.
	 * @param string $title   Card heading.
	 * @param string $desc    One sentence under the heading, or empty.
	 * @param string $section Section slug, for tests and for the search filter.
	 * @return void
	 */
	public function open_card( string $anchor, string $title, string $desc = '', string $section = '' ): void {
		printf(
			'<section class="pph-settings__card" id="%1$s" data-pph-settings-section="%2$s">',
			esc_attr( $anchor ),
			esc_attr( '' !== $section ? $section : $anchor )
		);

		echo '<div class="pph-settings__card-header">';
		printf( '<h3>%s</h3>', esc_html( $title ) );

		if ( '' !== $desc ) {
			printf( '<p>%s</p>', esc_html( $desc ) );
		}

		echo '</div><div class="pph-settings__card-body">';
	}

	/**
	 * Closes one card.
	 *
	 * @since 0.15.0
	 * @return void
	 */
	public static function close_card(): void {
		echo '</div></section>';
	}

	/**
	 * The element id of one section's card.
	 *
	 * @since 0.15.0
	 *
	 * @param string $tab     Tab slug.
	 * @param string $section Section slug.
	 * @return string
	 */
	public static function anchor( string $tab, string $section ): string {
		return 'pph-' . sanitize_key( $tab ) . '-' . sanitize_key( $section );
	}
}
