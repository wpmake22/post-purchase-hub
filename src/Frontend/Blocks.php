<?php
/**
 * Block surfaces.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

/**
 * Registers the server-rendered `pph/orders` block.
 *
 * Server-rendered rather than saved as markup, because the content is per
 * visitor: what a block saves into post content is public and cached, and a
 * customer's order history is neither. Registration reads the block.json the
 * build emits, so the editor and PHP cannot drift apart.
 *
 * @since 0.4.0
 */
final class Blocks {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const NAME = 'pph/orders';

	/**
	 * Built block metadata directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const METADATA_PATH = 'assets/build/blocks/orders';

	/**
	 * Shortcode service, which owns the shared rendering.
	 *
	 * @var Shortcodes
	 */
	private Shortcodes $shortcodes;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Shortcodes $shortcodes Shortcode service.
	 */
	public function __construct( Shortcodes $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Registers the block.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		$metadata = PPH_PLUGIN_DIR . self::METADATA_PATH;

		if ( ! is_readable( $metadata . '/block.json' ) ) {
			return;
		}

		register_block_type( $metadata, array( 'render_callback' => array( $this, 'render' ) ) );
	}

	/**
	 * Renders the block.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$limit      = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : Shortcodes::DEFAULT_LIMIT;

		$content = $this->shortcodes->render_for_current_user( $limit );

		if ( '' === $content ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes( array( 'class' => 'pph-orders' ) ),
			$content
		);
	}
}
