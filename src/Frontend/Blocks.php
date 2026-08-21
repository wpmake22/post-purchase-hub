<?php
/**
 * Block surfaces.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

/**
 * Registers this plugin's server-rendered blocks.
 *
 * Server-rendered rather than saved as markup, because the content is per
 * visitor: what a block saves into post content is public and cached, and a
 * customer's order history is neither. The lookup block is server-rendered for
 * a second reason — it disappears entirely on a store that has not enabled
 * guest lookup, which markup saved in a post could not do. Registration reads
 * the block.json the build emits, so the editor and PHP cannot drift apart.
 *
 * @since 0.4.0
 */
final class Blocks {

	/**
	 * Orders block name.
	 *
	 * @var string
	 */
	public const NAME = 'pph/orders';

	/**
	 * Guest-lookup block name.
	 *
	 * @var string
	 */
	public const LOOKUP_NAME = 'pph/order-lookup';

	/**
	 * Built block metadata directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const METADATA_PATH = 'assets/build/blocks/orders';

	/**
	 * Built lookup block metadata directory, relative to the plugin root.
	 *
	 * @var string
	 */
	private const LOOKUP_METADATA_PATH = 'assets/build/blocks/order-lookup';

	/**
	 * Shortcode service, which owns the shared rendering.
	 *
	 * @var Shortcodes
	 */
	private Shortcodes $shortcodes;

	/**
	 * Lookup form, which owns the shared lookup rendering.
	 *
	 * @var LookupForm
	 */
	private LookupForm $lookup;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Shortcodes $shortcodes Shortcode service.
	 * @param LookupForm $lookup     Lookup form service.
	 */
	public function __construct( Shortcodes $shortcodes, LookupForm $lookup ) {
		$this->shortcodes = $shortcodes;
		$this->lookup     = $lookup;
	}

	/**
	 * Registers the blocks.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		$metadata = PPH_PLUGIN_DIR . self::METADATA_PATH;

		if ( is_readable( $metadata . '/block.json' ) ) {
			register_block_type( $metadata, array( 'render_callback' => array( $this, 'render' ) ) );
		}

		$lookup_metadata = PPH_PLUGIN_DIR . self::LOOKUP_METADATA_PATH;

		if ( is_readable( $lookup_metadata . '/block.json' ) ) {
			register_block_type( $lookup_metadata, array( 'render_callback' => array( $this, 'render_lookup' ) ) );
		}
	}

	/**
	 * Renders the guest-lookup block.
	 *
	 * Returns nothing at all — not a wrapper, not a placeholder — when guest
	 * lookup is off, so a page that embedded the block before a merchant
	 * disabled the feature has no empty box left behind on it.
	 *
	 * @since 0.11.0
	 * @return string
	 */
	public function render_lookup(): string {
		$content = $this->lookup->render();

		if ( '' === $content ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes( array( 'class' => 'pph-lookup-block' ) ),
			$content
		);
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
