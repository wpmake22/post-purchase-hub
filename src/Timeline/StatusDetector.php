<?php
/**
 * Detection of the order statuses a store actually uses.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Timeline;

use PostPurchaseHub\Support\Cache;

/**
 * Reports which statuses appear on a store's recent orders.
 *
 * A six-stage timeline on a store that only ever goes processing → completed
 * shows four stages that will never fill, which customers read as broken. The
 * setup wizard fixes that by proposing a stage map built from what the store
 * has really done, and this is where that evidence comes from.
 *
 * Reading the sample costs real work, so the answer is cached; forget() exists
 * so the wizard can ask again after a merchant changes something.
 *
 * @since 0.3.0
 */
final class StatusDetector {

	/**
	 * Orders read when working out which statuses a store uses.
	 *
	 * @var int
	 */
	public const SAMPLE_SIZE = 200;

	/**
	 * How long a detection result is trusted, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache key holding the detected status list.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'timeline_used_statuses';

	/**
	 * Cache backing the detection.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param Cache $cache Cache to store the result in.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * The statuses this store has used recently.
	 *
	 * @since 0.3.0
	 *
	 * @param bool $refresh Skip the cached answer and read the sample again.
	 * @return array<int, string> Unprefixed status slugs, alphabetical.
	 */
	public function detect( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = $this->cache->get( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return array_values( array_filter( $cached, 'is_string' ) );
			}
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'   => self::SAMPLE_SIZE,
				'type'    => 'shop_order',
				'status'  => 'any',
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		$statuses = array();

		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof \WC_Order ) {
				$statuses[ StageMap::normalise_status( $order->get_status() ) ] = true;
			}
		}

		$detected = array_keys( $statuses );
		sort( $detected );

		$this->cache->set( self::CACHE_KEY, $detected, self::TTL );

		return $detected;
	}

	/**
	 * Drops the cached detection result.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->cache->delete( self::CACHE_KEY );
	}
}
