<?php
/**
 * Shortcode surfaces.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Install\SetupState;

/**
 * Registers `[pph_orders]`.
 *
 * The shortcode lists the orders belonging to whoever is looking at the page
 * and takes no order identifier of any kind. That is deliberate: WooCommerce
 * order ids are sequential and guessable, so an attribute naming an order would
 * turn a page a merchant can embed anywhere into an enumeration tool. Ownership
 * is not checked here because nothing here accepts an identity to check —
 * the query is scoped to the current session and returns nothing to a guest.
 *
 * @since 0.4.0
 */
final class Shortcodes {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public const TAG = 'pph_orders';

	/**
	 * Most orders one embed will ever list.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Orders listed when the embed does not say.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers the shortcode.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array( 'limit' => self::DEFAULT_LIMIT ),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		return $this->render_for_current_user( (int) $atts['limit'] );
	}

	/**
	 * Renders the current user's orders, or the signed-out message.
	 *
	 * Shared with the block, which offers the same thing through a different
	 * editor.
	 *
	 * @since 0.4.0
	 *
	 * @param int $limit Requested number of orders.
	 * @return string
	 */
	public function render_for_current_user( int $limit ): string {
		// An unconfigured store renders nothing on the storefront
		// (docs/MILESTONE-PROMPTS.md M14). The shortcode stays registered so a
		// page that embeds it shows an empty section rather than printing the
		// raw shortcode text at customers.
		if ( ! SetupState::is_complete() ) {
			return '';
		}

		$customer_id = get_current_user_id();

		if ( 0 === $customer_id ) {
			return '<p class="pph-orders__empty" data-pph-orders-empty>'
				. esc_html__( 'Sign in to see your orders.', 'wpmake-post-purchase-hub' )
				. '</p>';
		}

		$orders = wc_get_orders(
			array(
				'customer' => $customer_id,
				'limit'    => $this->clamp( $limit ),
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		ob_start();

		$this->renderer->render_orders_list( is_array( $orders ) ? $orders : array() );

		return (string) ob_get_clean();
	}

	/**
	 * Keeps a requested limit inside something a page can render.
	 *
	 * @since 0.4.0
	 *
	 * @param int $limit Requested limit.
	 * @return int
	 */
	private function clamp( int $limit ): int {
		if ( $limit < 1 ) {
			return self::DEFAULT_LIMIT;
		}

		return min( $limit, self::MAX_LIMIT );
	}
}
