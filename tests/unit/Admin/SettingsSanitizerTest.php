<?php
/**
 * Settings sanitisation unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Actions\ActionAvailability;
use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Admin\SettingsFields;
use PostPurchaseHub\Admin\SettingsSanitizer;
use PostPurchaseHub\Frontend\TemplateReplacer;
use PostPurchaseHub\Install\Uninstaller;
use PostPurchaseHub\Requests\RetentionSweeper;
use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Timeline\EstimatedDelivery;
use PostPurchaseHub\Timeline\StageMapConfig;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * M14 asks for a "sanitisation unit test per field including malformed and
 * hostile input" and "every setting round-trips and sanitises;
 * malformed input never fatals".
 *
 * Every declared field is exercised twice over: once with a value a merchant
 * would type, and once with something a form never produces — a string where an
 * array belongs, an array where an integer belongs, a nested array, a script
 * tag, a value outside its own bounds.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Admin\SettingsSanitizer
 * @covers \PostPurchaseHub\Admin\SettingsFields
 * @covers \PostPurchaseHub\Admin\SettingsStatusValues
 * @covers \PostPurchaseHub\Admin\SettingsShippingValues
 */
final class SettingsSanitizerTest extends TestCase {

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
	 * Sanitises one field by key.
	 *
	 * @param string $key   Settings key.
	 * @param mixed  $value Posted value.
	 * @return mixed
	 */
	private function clean( string $key, $value ) {
		$field = SettingsFields::get( $key );

		$this->assertIsArray( $field, $key . ' is a declared field.' );

		return SettingsSanitizer::sanitize_field( $value, $field );
	}

	/**
	 * Values no form produces, for the "never fatals" requirement.
	 *
	 * @return array<int, mixed>
	 */
	private function hostile(): array {
		return array(
			null,
			'',
			'<script>alert(1)</script>',
			array( 'nested' => array( 'deeper' => array( 'deepest' ) ) ),
			array( new \stdClass() ),
			-99999,
			'99999999999999999999',
			true,
			0.5,
			array( '<script>' => '<script>' ),
		);
	}

	/**
	 * Every declared field survives every hostile value without fataling, and
	 * returns something of the type the reading service expects.
	 *
	 * @return void
	 */
	public function test_every_field_survives_hostile_input(): void {
		foreach ( SettingsFields::all() as $key => $field ) {
			foreach ( $this->hostile() as $value ) {
				$clean = SettingsSanitizer::sanitize_field( $value, $field );
				$type  = gettype( $field['default'] );

				$this->assertSame(
					$type,
					gettype( $clean ),
					$key . ' must stay a ' . $type . ' whatever is posted to it.'
				);
			}
		}
	}

	/**
	 * A checkbox that was not posted is off, not "as it was".
	 *
	 * @return void
	 */
	public function test_an_absent_checkbox_is_off(): void {
		$this->assertFalse( $this->clean( Cancel::RESTOCK_SETTING, null ) );
		$this->assertTrue( $this->clean( Cancel::RESTOCK_SETTING, '1' ) );
		$this->assertFalse( $this->clean( Uninstaller::SETTING, null ) );
	}

	/**
	 * Numbers are held inside their declared bounds rather than trusted.
	 *
	 * @return void
	 */
	public function test_numbers_are_bounded(): void {
		$this->assertSame( TokenService::MAX_TTL_DAYS, $this->clean( TokenService::TTL_SETTING, 9999 ) );
		$this->assertSame( 1, $this->clean( TokenService::TTL_SETTING, 0 ) );
		$this->assertSame( 14, $this->clean( TokenService::TTL_SETTING, 14 ) );

		$this->assertSame( 50, $this->clean( Cancel::CAP_SETTING, 5000 ) );
		$this->assertSame( 1, $this->clean( Cancel::CAP_SETTING, -3 ) );

		$this->assertSame( 0, $this->clean( RetentionSweeper::RETENTION_SETTING, 0 ), 'Zero is a real answer here: keep everything.' );
		$this->assertSame( RetentionSweeper::MAX_RETENTION_DAYS, $this->clean( RetentionSweeper::RETENTION_SETTING, 99999 ) );
	}

	/**
	 * A non-numeric number falls back to the field's own default rather than to
	 * zero, which would silently mean something different.
	 *
	 * @return void
	 */
	public function test_a_non_numeric_number_falls_back_to_its_default(): void {
		$this->assertSame(
			EstimatedDelivery::DEFAULT_HANDLING_DAYS,
			$this->clean( EstimatedDelivery::HANDLING_SETTING, 'tomorrow' )
		);
	}

	/**
	 * A choice outside its own list is refused.
	 *
	 * @return void
	 */
	public function test_a_choice_must_be_one_of_its_choices(): void {
		$this->assertSame(
			TemplateReplacer::MODE_REPLACEMENT,
			$this->clean( TemplateReplacer::SETTING, TemplateReplacer::MODE_REPLACEMENT )
		);

		$this->assertSame(
			TemplateReplacer::MODE_ADDITIVE,
			$this->clean( TemplateReplacer::SETTING, 'take-over-the-whole-site' )
		);
	}

	/**
	 * Statuses are reduced to the ones this store has, prefix or no prefix.
	 *
	 * @return void
	 */
	public function test_statuses_are_checked_against_the_store(): void {
		$clean = $this->clean(
			Cancel::STATUSES_SETTING,
			array( 'wc-processing', 'on-hold', 'invented-status', '<script>', 'processing' )
		);

		$this->assertSame( array( 'processing', 'on-hold' ), $clean );
	}

	/**
	 * A status map keeps "not shown" as a real answer and drops unknown statuses.
	 *
	 * @return void
	 */
	public function test_the_status_map_keeps_hidden_and_drops_unknown(): void {
		$clean = $this->clean(
			StageMapConfig::MAP_SETTING,
			array(
				'processing'   => 'in_progress',
				'on-hold'      => StageMapConfig::HIDDEN,
				'not-a-status' => 'in_progress',
			)
		);

		$this->assertSame( 'in_progress', $clean['processing'] );
		$this->assertSame( StageMapConfig::HIDDEN, $clean['on-hold'] );
		$this->assertArrayNotHasKey( 'not-a-status', $clean );
	}

	/**
	 * Weekdays are 0–6, unique and sorted.
	 *
	 * @return void
	 */
	public function test_weekdays_are_bounded_unique_and_sorted(): void {
		$clean = $this->clean( EstimatedDelivery::WEEKEND_SETTING, array( 6, 0, 6, 9, -1, '3' ) );

		$this->assertSame( array( 0, 3, 6 ), $clean );
	}

	/**
	 * Holidays must be real dates, from either a textarea or an array.
	 *
	 * @return void
	 */
	public function test_holidays_must_be_real_dates(): void {
		$clean = $this->clean(
			EstimatedDelivery::HOLIDAYS_SETTING,
			"2026-12-25\n2026-02-31\nnot-a-date\n2026-01-01\n2026-12-25"
		);

		$this->assertSame( array( '2026-12-25', '2026-01-01' ), $clean );
	}

	/**
	 * A pasted holiday list cannot grow the option without bound.
	 *
	 * @return void
	 */
	public function test_the_holiday_list_is_capped(): void {
		$dates = array();

		for ( $day = 1; $day <= 400; $day++ ) {
			$dates[] = gmdate( 'Y-m-d', strtotime( '2026-01-01 +' . $day . ' days' ) );
		}

		$clean = $this->clean( EstimatedDelivery::HOLIDAYS_SETTING, $dates );

		$this->assertCount( SettingsSanitizer::MAX_HOLIDAYS, $clean );
	}

	/**
	 * Per-method handling days keep WooCommerce's own instance ids and drop
	 * anything that is not one.
	 *
	 * @return void
	 */
	public function test_method_days_keep_instance_ids(): void {
		$clean = $this->clean(
			EstimatedDelivery::HANDLING_OVERRIDES_SETTING,
			array(
				'flat_rate:3'   => '2',
				'local_pickup'  => '0',
				'blank'         => '',
				'bad key!'      => '4',
				'free_shipping' => 'soon',
			)
		);

		$this->assertSame(
			array(
				'flat_rate:3'  => 2,
				'local_pickup' => 0,
			),
			$clean
		);
	}

	/**
	 * A half-filled transit range is dropped, not half-stored: an unconfigured
	 * method deliberately shows no estimate rather than a guessed one.
	 *
	 * @return void
	 */
	public function test_a_half_filled_transit_range_is_dropped(): void {
		$clean = $this->clean(
			EstimatedDelivery::TRANSIT_SETTING,
			array(
				'flat_rate'    => array(
					'min' => '5',
					'max' => '2',
				),
				'local_pickup' => array( 'min' => '1' ),
				'express'      => array(
					'min' => '',
					'max' => '',
				),
			)
		);

		$this->assertSame(
			array(
				'flat_rate' => array(
					'min' => 2,
					'max' => 5,
				),
			),
			$clean,
			'A reversed range is corrected; an incomplete one is dropped.'
		);
	}

	/**
	 * Action switches are restricted to the actions this build has.
	 *
	 * @return void
	 */
	public function test_action_toggles_are_restricted_to_real_actions(): void {
		$clean = $this->clean(
			ActionAvailability::SETTING,
			array(
				'cancel'          => '1',
				'reorder'         => '0',
				'delete_database' => '1',
			)
		);

		$this->assertSame( array_keys( ActionAvailability::DEFAULTS ), array_keys( $clean ) );
		$this->assertTrue( $clean['cancel'] );
		$this->assertFalse( $clean['reorder'] );
		$this->assertArrayNotHasKey( 'delete_database', $clean );
	}

	/**
	 * Saving one tab leaves the other tabs alone — the bug a single option
	 * behind six tabs invites.
	 *
	 * @return void
	 */
	public function test_saving_one_tab_leaves_the_others_alone(): void {
		$existing = array(
			Cancel::CAP_SETTING                 => 7,
			EstimatedDelivery::HANDLING_SETTING => 3,
		);

		$clean = SettingsSanitizer::sanitize_tab(
			array( EstimatedDelivery::HANDLING_SETTING => 5 ),
			'timeline',
			$existing
		);

		$this->assertSame( 5, $clean[ EstimatedDelivery::HANDLING_SETTING ] );
		$this->assertSame( 7, $clean[ Cancel::CAP_SETTING ], 'The Actions tab was not being saved and must be untouched.' );
	}

	/**
	 * Nothing undeclared is ever written, whatever else was posted.
	 *
	 * @return void
	 */
	public function test_undeclared_keys_are_never_stored(): void {
		$clean = SettingsSanitizer::sanitize_tab(
			array(
				'pph_token_secret'   => 'stolen',
				'active_plugins'     => array( 'evil/evil.php' ),
				Uninstaller::SETTING => '1',
			),
			'advanced',
			array()
		);

		$this->assertArrayNotHasKey( 'pph_token_secret', $clean );
		$this->assertArrayNotHasKey( 'active_plugins', $clean );
		$this->assertTrue( $clean[ Uninstaller::SETTING ], 'The declared field on that tab still saves.' );
	}

	/**
	 * Guest access cannot be stored as enabled without its acknowledgement.
	 *
	 * @return void
	 */
	public function test_guest_access_needs_its_acknowledgement(): void {
		$without = SettingsSanitizer::sanitize_tab(
			array( GuestAccess::ENABLED_SETTING => '1' ),
			'guest',
			array()
		);

		$this->assertFalse( $without[ GuestAccess::ENABLED_SETTING ] );

		$with = SettingsSanitizer::sanitize_tab(
			array(
				GuestAccess::ENABLED_SETTING      => '1',
				GuestAccess::ACKNOWLEDGED_SETTING => '1',
			),
			'guest',
			array()
		);

		$this->assertTrue( $with[ GuestAccess::ENABLED_SETTING ] );
		$this->assertTrue( $with[ GuestAccess::ACKNOWLEDGED_SETTING ] );
	}

	/**
	 * Un-acknowledging afterwards switches guest access back off rather than
	 * leaving it running unacknowledged.
	 *
	 * @return void
	 */
	public function test_withdrawing_the_acknowledgement_disables_guest_access(): void {
		$clean = SettingsSanitizer::sanitize_tab(
			array( GuestAccess::ENABLED_SETTING => '1' ),
			'guest',
			array(
				GuestAccess::ENABLED_SETTING      => true,
				GuestAccess::ACKNOWLEDGED_SETTING => true,
			)
		);

		$this->assertFalse( $clean[ GuestAccess::ENABLED_SETTING ] );
	}

	/**
	 * Every field belongs to a real tab and has a default, so the screen can
	 * always render it and a reading service always has something to read.
	 *
	 * @return void
	 */
	public function test_every_field_is_completely_declared(): void {
		foreach ( SettingsFields::all() as $key => $field ) {
			$this->assertTrue( SettingsFields::is_tab( (string) $field['tab'] ), $key . ' is on a real tab.' );
			$this->assertArrayHasKey( 'default', $field, $key . ' has a default.' );
			$this->assertArrayHasKey( 'label', $field, $key . ' has a label.' );
			$this->assertArrayHasKey( 'help', $field, $key . ' has inline help, per M14.' );
			$this->assertNotSame( '', (string) $field['help'], $key . '\'s help is not empty.' );
		}
	}
}
