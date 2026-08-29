<?php
/**
 * Stored stage map unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Timeline;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\StageMap;
use PostPurchaseHub\Timeline\StageMapConfig;
use PostPurchaseHub\Timeline\StatusDetector;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The wizard's first answer, put into effect through the timeline's own
 * documented filter rather than through a second source of truth inside
 * `Timeline\StageMap`.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Timeline\StageMapConfig
 */
final class StageMapConfigTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * Stores a status map.
	 *
	 * @param array<string, string> $map Map to store.
	 * @return void
	 */
	private function store( array $map ): void {
		FakeWordPress::$options['wpmphub_settings'] = array( StageMapConfig::MAP_SETTING => $map );
	}

	/**
	 * A live stage map, with the config layer registered.
	 *
	 * @return StageMap
	 */
	private function stages(): StageMap {
		( new StageMapConfig() )->register();

		return new StageMap( new StatusDetector( new Cache() ) );
	}

	/**
	 * With nothing stored, the shipped map is untouched.
	 *
	 * @return void
	 */
	public function test_it_changes_nothing_by_default(): void {
		$default = ( new StageMap( new StatusDetector( new Cache() ) ) )->status_map();

		$this->assertSame( $default, $this->stages()->status_map() );
	}

	/**
	 * A merchant's answer moves a status to another stage.
	 *
	 * @return void
	 */
	public function test_a_stored_answer_moves_a_status(): void {
		$this->store( array( 'on-hold' => 'packed' ) );

		$this->assertSame( 'packed', $this->stages()->stage_for_status( 'on-hold' ) );
	}

	/**
	 * "Not shown" really hides a status, which is how an internal status stays
	 * internal.
	 *
	 * @return void
	 */
	public function test_hidden_means_hidden(): void {
		$this->store( array( 'processing' => StageMapConfig::HIDDEN ) );

		$this->assertNull( $this->stages()->stage_for_status( 'processing' ) );
	}

	/**
	 * A status the merchant never saw keeps its shipped mapping, rather than
	 * disappearing because it was not in the form.
	 *
	 * @return void
	 */
	public function test_unanswered_statuses_keep_their_defaults(): void {
		$this->store( array( 'on-hold' => 'packed' ) );

		$this->assertSame( 'delivered', $this->stages()->stage_for_status( 'completed' ) );
	}

	/**
	 * A stage that does not exist is dropped by StageMap's own cleaning, so a
	 * stale stored map cannot invent a stage.
	 *
	 * @return void
	 */
	public function test_an_unknown_stage_is_dropped(): void {
		$this->store( array( 'processing' => 'teleported' ) );

		$this->assertNull( $this->stages()->stage_for_status( 'processing' ) );
	}

	/**
	 * A developer's own filter still wins: stored configuration is a better
	 * default, not an override of code.
	 *
	 * @return void
	 */
	public function test_a_later_filter_still_wins(): void {
		$this->store( array( 'processing' => 'packed' ) );

		// Registration order, as in production: this layer is wired at plugin
		// load, so a developer's own filter is always the later one.
		( new StageMapConfig() )->register();

		FakeWordPress::$filters['wpmphub_status_stage_map'][] = static function ( $map ) {
			$map['processing'] = 'shipped';

			return $map;
		};

		$stages = new StageMap( new StatusDetector( new Cache() ) );

		$this->assertSame( 'shipped', $stages->stage_for_status( 'processing' ) );
	}

	/**
	 * A corrupted stored map is ignored rather than fataling.
	 *
	 * @return void
	 */
	public function test_a_corrupted_map_is_ignored(): void {
		FakeWordPress::$options['wpmphub_settings'] = array( StageMapConfig::MAP_SETTING => 'nonsense' );

		$this->assertSame( array(), StageMapConfig::stored() );

		$this->store( array( 'processing' => array( 'nested' ) ) );

		$this->assertSame( array(), StageMapConfig::stored() );
	}
}
