<?php
/**
 * GuestAccess unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers CLAUDE.md hard rule 15 and docs/MILESTONE-PROMPTS.md M11 point 7:
 * guest access is off until explicitly enabled, and enabling it takes the
 * acknowledgement step as well as the switch.
 *
 * @since 0.11.0
 *
 * @covers \PostPurchaseHub\Security\GuestAccess
 */
final class GuestAccessTest extends TestCase {

	/**
	 * Gate under test.
	 *
	 * @var GuestAccess
	 */
	private GuestAccess $access;

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$this->access = new GuestAccess();
	}

	/**
	 * Stores a settings array.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ): void {
		FakeWordPress::$options['pph_settings'] = $settings;
	}

	/**
	 * A store that has never been configured exposes nothing.
	 *
	 * @return void
	 */
	public function test_it_is_off_on_a_fresh_install(): void {
		$this->assertFalse( $this->access->is_enabled() );
	}

	/**
	 * The switch alone is not enough.
	 *
	 * @return void
	 */
	public function test_the_switch_alone_does_not_enable_it(): void {
		$this->settings( array( GuestAccess::ENABLED_SETTING => true ) );

		$this->assertFalse( $this->access->is_enabled() );
	}

	/**
	 * Nor is the acknowledgement alone.
	 *
	 * @return void
	 */
	public function test_the_acknowledgement_alone_does_not_enable_it(): void {
		$this->settings( array( GuestAccess::ACKNOWLEDGED_SETTING => true ) );

		$this->assertFalse( $this->access->is_enabled() );
	}

	/**
	 * Both together do.
	 *
	 * @return void
	 */
	public function test_both_together_enable_it(): void {
		$this->settings(
			array(
				GuestAccess::ENABLED_SETTING      => true,
				GuestAccess::ACKNOWLEDGED_SETTING => true,
			)
		);

		$this->assertTrue( $this->access->is_enabled() );
	}

	/**
	 * The filter can switch the surface off.
	 *
	 * @return void
	 */
	public function test_the_filter_can_disable_it(): void {
		$this->settings(
			array(
				GuestAccess::ENABLED_SETTING      => true,
				GuestAccess::ACKNOWLEDGED_SETTING => true,
			)
		);

		add_filter(
			'pph_guest_lookup_enabled',
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $this->access->is_enabled() );
	}

	/**
	 * The filter cannot switch it on: the acknowledgement stays mandatory, so
	 * a line in a theme's functions.php cannot open a public order endpoint.
	 *
	 * @return void
	 */
	public function test_the_filter_cannot_enable_it(): void {
		add_filter(
			'pph_guest_lookup_enabled',
			static function (): bool {
				return true;
			}
		);

		$this->assertFalse( $this->access->is_enabled() );
	}

	/**
	 * A corrupted option is not an accidental opt-in.
	 *
	 * @return void
	 */
	public function test_a_non_array_option_leaves_it_off(): void {
		FakeWordPress::$options['pph_settings'] = 'not-an-array';

		$this->assertFalse( $this->access->is_enabled() );
	}
}
