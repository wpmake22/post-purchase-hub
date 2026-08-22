<?php
/**
 * Uninstall integration tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Install\Uninstaller;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestRepository;

/**
 * Both branches of the retention setting, because getting this wrong in either
 * direction is unrecoverable: one deletes a merchant's history uninvited, the
 * other leaves data behind after they asked for it to go.
 *
 * @since 0.2.0
 *
 * @covers \PostPurchaseHub\Install\Uninstaller
 */
final class UninstallTest extends \WP_UnitTestCase {

	/**
	 * Uses real tables, so that dropping them means something.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// This class exercises schema lifecycle — creating and dropping real
		// tables — so it opts out of WP_UnitTestCase's DDL rewriting.
		// WP_UnitTestCase turns CREATE TABLE into CREATE TEMPORARY TABLE and
		// DROP TABLE into DROP TEMPORARY TABLE, and `SHOW TABLES` (which
		// Schema::table_exists() uses, correctly, in production) cannot see a
		// temporary table at all. Left in place, an install is invisible and a
		// drop silently misses the real table these assertions then find.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Creates the tables once, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name fixed by WP_UnitTestCase.
		unset( $factory );

		Schema::install();
	}

	/**
	 * Restores anything a test tore down.
	 *
	 * DROP TABLE commits the surrounding transaction, so this suite cannot rely
	 * on the usual rollback and puts the schema back itself.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'pph_settings' );

		Schema::install();

		parent::tear_down();
	}

	/**
	 * With the setting absent, nothing is removed.
	 *
	 * @return void
	 */
	public function test_nothing_is_removed_by_default(): void {
		Activator::activate();
		$id = $this->seed();

		$this->assertFalse( Uninstaller::deletion_allowed() );
		$this->assertFalse( Uninstaller::run() );

		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
		$this->assertNotNull( ( new RequestRepository() )->find( $id ) );
		$this->assertNotSame( '', (string) get_option( Activator::TOKEN_SECRET_OPTION ) );
	}

	/**
	 * A falsy setting is still a no.
	 *
	 * @dataProvider falsy_provider
	 *
	 * @param mixed $value Stored setting value.
	 * @return void
	 */
	public function test_a_falsy_setting_is_a_no( $value ): void {
		update_option( 'pph_settings', array( Uninstaller::SETTING => $value ) );

		$this->assertFalse( Uninstaller::deletion_allowed() );
		$this->assertFalse( Uninstaller::run() );
		$this->assertTrue( Schema::table_exists( Schema::requests_table() ) );
	}

	/**
	 * Values that must not authorise deletion.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function falsy_provider(): array {
		return array(
			'false'        => array( false ),
			'zero'         => array( 0 ),
			'string zero'  => array( '0' ),
			'empty string' => array( '' ),
			'null'         => array( null ),
		);
	}

	/**
	 * With the setting on, the tables and options go.
	 *
	 * @return void
	 */
	public function test_everything_is_removed_when_asked(): void {
		Activator::activate();
		$this->seed();

		update_option( 'pph_settings', array( Uninstaller::SETTING => true ) );

		$this->assertTrue( Uninstaller::run() );

		$this->assertFalse( Schema::table_exists( Schema::requests_table() ) );
		$this->assertFalse( Schema::table_exists( Schema::request_items_table() ) );
		$this->assertFalse( get_option( Activator::TOKEN_SECRET_OPTION, false ) );
		$this->assertFalse( get_option( Activator::SCHEMA_VERSION_OPTION, false ) );
		$this->assertFalse( get_option( 'pph_settings', false ) );
		$this->assertFalse( wp_next_scheduled( Activator::CLEANUP_HOOK ) );
	}

	/**
	 * This plugin's order meta is removed through the CRUD layer, which is what
	 * makes it work the same way under HPOS and legacy storage.
	 *
	 * @return void
	 */
	public function test_order_meta_is_removed_through_crud(): void {
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->update_meta_data( '_pph_timeline', array( array( 'status' => 'processing' ) ) );
		$order->update_meta_data( '_pph_eta', '2026-08-25' );
		$order->update_meta_data( '_other_plugin_key', 'keep me' );
		$order->save();

		$result = Uninstaller::delete_order_meta();

		$this->assertGreaterThanOrEqual( 1, $result['orders_scanned'] );
		$this->assertGreaterThanOrEqual( 1, $result['orders_cleaned'] );

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( '', $reloaded->get_meta( '_pph_timeline' ) );
		$this->assertSame( '', $reloaded->get_meta( '_pph_eta' ) );
		$this->assertSame( 'keep me', $reloaded->get_meta( '_other_plugin_key' ) );
	}

	/**
	 * Another plugin's meta is never touched, whatever it is called.
	 *
	 * @return void
	 */
	public function test_other_plugins_meta_survives(): void {
		$order = new \WC_Order();
		$order->update_meta_data( '_wc_shipment_tracking_items', 'tracking' );
		$order->update_meta_data( 'pph_no_underscore', 'not ours by convention' );
		$order->save();

		Uninstaller::delete_order_meta();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'tracking', $reloaded->get_meta( '_wc_shipment_tracking_items' ) );
		$this->assertSame( 'not ours by convention', $reloaded->get_meta( 'pph_no_underscore' ) );
	}

	/**
	 * The order sweep is bounded, and says when it did not finish.
	 *
	 * @return void
	 */
	public function test_the_order_sweep_is_bounded(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$order = new \WC_Order();
			$order->update_meta_data( '_pph_timeline', array() );
			$order->save();
		}

		$result = Uninstaller::delete_order_meta( 1, 2, 30 );

		$this->assertSame( 2, $result['orders_scanned'] );
		$this->assertTrue( $result['remaining'] );
	}

	/**
	 * Running the sweep again finds nothing left to do.
	 *
	 * @return void
	 */
	public function test_the_order_sweep_is_idempotent(): void {
		$order = new \WC_Order();
		$order->update_meta_data( '_pph_timeline', array() );
		$order->save();

		Uninstaller::delete_order_meta();
		$second = Uninstaller::delete_order_meta();

		$this->assertSame( 0, $second['orders_cleaned'] );
	}

	/**
	 * Inserts one request row.
	 *
	 * @return int
	 */
	private function seed(): int {
		return ( new RequestRepository() )->create(
			array(
				'order_id' => 4242,
				'type'     => Request::TYPE_CANCELLATION,
				'source'   => Request::SOURCE_ACCOUNT,
			)
		);
	}
}
