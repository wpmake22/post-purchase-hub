<?php
/**
 * Stage map unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StatusDetector;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the default map, the two filters and status detection.
 *
 * @since 0.3.0
 *
 * @covers \PostPurchaseHub\Timeline\StageMap
 */
final class StageMapTest extends TestCase {

	/**
	 * Clears the fake WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Builds a stage map over a fresh cache.
	 *
	 * @return StageMap
	 */
	private function map(): StageMap {
		return new StageMap( new StatusDetector( new Cache() ) );
	}

	/**
	 * The six progress stages arrive in the order the spec lists them.
	 *
	 * @return void
	 */
	public function test_default_stages_are_ordered(): void {
		$this->assertSame(
			array( 'placed', 'confirmed', 'packed', 'shipped', 'out_for_delivery', 'delivered' ),
			array_keys( $this->map()->stages() )
		);
	}

	/**
	 * Branch states are separate from the progress line.
	 *
	 * @return void
	 */
	public function test_branch_states_are_not_progress_stages(): void {
		$map = $this->map();

		foreach ( array_keys( $map->branches() ) as $branch ) {
			$this->assertTrue( $map->is_branch( (string) $branch ) );
			$this->assertSame( -1, $map->position( (string) $branch ) );
			$this->assertArrayNotHasKey( $branch, $map->stages() );
		}
	}

	/**
	 * Every core status lands somewhere, prefixed or not.
	 *
	 * @dataProvider core_statuses
	 *
	 * @param string $status Order status.
	 * @param string $stage  Expected stage key.
	 * @return void
	 */
	public function test_core_statuses_map_to_stages( string $status, string $stage ): void {
		$map = $this->map();

		$this->assertSame( $stage, $map->stage_for_status( $status ) );
		$this->assertSame( $stage, $map->stage_for_status( 'wc-' . $status ) );
	}

	/**
	 * Status to stage pairs for the WooCommerce core statuses.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function core_statuses(): array {
		return array(
			'pending'    => array( 'pending', StageMap::PLACED ),
			'processing' => array( 'processing', StageMap::CONFIRMED ),
			'completed'  => array( 'completed', StageMap::DELIVERED ),
			'on-hold'    => array( 'on-hold', StageMap::ON_HOLD ),
			'cancelled'  => array( 'cancelled', StageMap::CANCELLED ),
			'refunded'   => array( 'refunded', StageMap::REFUNDED ),
			'failed'     => array( 'failed', StageMap::FAILED ),
		);
	}

	/**
	 * A status nobody mapped contributes nothing.
	 *
	 * @return void
	 */
	public function test_unmapped_status_has_no_stage(): void {
		$this->assertNull( $this->map()->stage_for_status( 'checkout-draft' ) );
	}

	/**
	 * Merchants can add a stage and point a custom status at it.
	 *
	 * @return void
	 */
	public function test_filters_add_a_stage_and_a_status(): void {
		add_filter(
			'wpmphub_timeline_stages',
			static function ( array $stages ): array {
				return array_merge( $stages, array( 'collected' => 'Collected' ) );
			}
		);

		add_filter(
			'wpmphub_status_stage_map',
			static function ( array $map ): array {
				return array_merge( $map, array( 'wc-collected' => 'collected' ) );
			}
		);

		$map = $this->map();

		$this->assertSame( 'collected', $map->stage_for_status( 'collected' ) );
		$this->assertSame( 6, $map->position( 'collected' ) );
	}

	/**
	 * A filter pointing a status at a stage that does not exist is dropped.
	 *
	 * @return void
	 */
	public function test_status_mapped_to_an_unknown_stage_is_dropped(): void {
		add_filter(
			'wpmphub_status_stage_map',
			static function ( array $map ): array {
				return array_merge( $map, array( 'processing' => 'teleported' ) );
			}
		);

		$this->assertNull( $this->map()->stage_for_status( 'processing' ) );
	}

	/**
	 * A filter returning rubbish leaves the customer with the default timeline.
	 *
	 * @dataProvider broken_filter_returns
	 *
	 * @param mixed $value Value the filter returns.
	 * @return void
	 */
	public function test_broken_stage_filter_falls_back_to_defaults( $value ): void {
		add_filter(
			'wpmphub_timeline_stages',
			static function () use ( $value ) {
				return $value;
			}
		);

		$this->assertSame(
			array( 'placed', 'confirmed', 'packed', 'shipped', 'out_for_delivery', 'delivered' ),
			array_keys( $this->map()->stages() )
		);
	}

	/**
	 * Values a badly written filter might return.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function broken_filter_returns(): array {
		return array(
			'null'         => array( null ),
			'string'       => array( 'nope' ),
			'empty array'  => array( array() ),
			'no labels'    => array( array( 'placed' => array() ) ),
			'numeric keys' => array( array( 0 => 'Placed' ) ),
		);
	}

	/**
	 * Detection reports the distinct statuses in the sample, alphabetically.
	 *
	 * @return void
	 */
	public function test_detection_reports_distinct_statuses(): void {
		FakeWordPress::$orders = array(
			1 => new \WC_Order( 1, 'processing' ),
			2 => new \WC_Order( 2, 'completed' ),
			3 => new \WC_Order( 3, 'processing' ),
		);

		$this->assertSame( array( 'completed', 'processing' ), $this->map()->detect_used_statuses() );
	}

	/**
	 * The second call reads the cache, not the store.
	 *
	 * @return void
	 */
	public function test_detection_is_cached_and_can_be_forgotten(): void {
		FakeWordPress::$orders = array( 1 => new \WC_Order( 1, 'processing' ) );

		$map = $this->map();

		$this->assertSame( array( 'processing' ), $map->detect_used_statuses() );

		FakeWordPress::$orders = array( 1 => new \WC_Order( 1, 'completed' ) );

		$this->assertSame( array( 'processing' ), $map->detect_used_statuses() );

		$map->forget_used_statuses();

		$this->assertSame( array( 'completed' ), $map->detect_used_statuses() );
	}

	/**
	 * The detection result is cached for the documented twelve hours.
	 *
	 * @return void
	 */
	public function test_detection_uses_a_twelve_hour_ttl(): void {
		FakeWordPress::$orders = array( 1 => new \WC_Order( 1, 'processing' ) );

		$this->map()->detect_used_statuses();

		$written = array_merge( ...array_values( FakeWordPress::$transient_writes ) );

		$this->assertSame( array( StatusDetector::TTL ), $written );
	}
}
