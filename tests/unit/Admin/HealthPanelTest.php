<?php
/**
 * Health panel unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Admin\HealthPanel;
use PostPurchaseHub\Admin\TemplateConflictScanner;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Install\Schema;
use PostPurchaseHub\Install\SetupState;
use PostPurchaseHub\Integrations\Invoices\Detector;
use PostPurchaseHub\Support\Cache;
use PostPurchaseHub\Tests\Unit\Integrations\Invoices\FakeInvoiceProvider;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;
use PostPurchaseHub\Tests\Unit\Support\FakeWpdb;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The list docs/SPEC.md gives for this panel — detected tracking plugin,
 * detected invoice plugin, template conflicts, cron status, schema version — and
 * its one rule: say what was found, not what should have been.
 *
 * @since 0.14.0
 *
 * @covers \PostPurchaseHub\Admin\HealthPanel
 */
final class HealthPanelTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();

		$GLOBALS['wpdb'] = new FakeWpdb( array( Schema::REQUESTS ) );
	}

	/**
	 * A panel over a given invoice detector.
	 *
	 * @param Detector|null $invoices Detector to use, or one that finds nothing.
	 * @return HealthPanel
	 */
	private function panel( ?Detector $invoices = null ): HealthPanel {
		$cache = new Cache();

		return new HealthPanel(
			new TemplateConflictScanner( $cache ),
			$invoices ?? new Detector( $cache, array() )
		);
	}

	/**
	 * One row by id.
	 *
	 * @param HealthPanel $panel Panel to read.
	 * @param string      $id    Row id.
	 * @return array{id: string, label: string, value: string, state: string}
	 */
	private function row( HealthPanel $panel, string $id ): array {
		foreach ( $panel->rows() as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}

		$this->fail( 'There is no ' . $id . ' row.' );
	}

	/**
	 * The panel answers all five of the spec's questions, plus setup.
	 *
	 * @return void
	 */
	public function test_it_reports_every_row_the_spec_asks_for(): void {
		$ids = array_map(
			static function ( array $row ): string {
				return $row['id'];
			},
			$this->panel()->rows()
		);

		$this->assertSame( array( 'setup', 'tracking', 'invoices', 'templates', 'cron', 'schema' ), $ids );
	}

	/**
	 * An unconfigured store is told, in the one place a merchant looks, that
	 * customers are seeing nothing yet.
	 *
	 * @return void
	 */
	public function test_it_says_when_the_storefront_is_dark(): void {
		$row = $this->row( $this->panel(), 'setup' );

		$this->assertSame( HealthPanel::NOTICE, $row['state'] );

		SetupState::complete();

		$this->assertSame( HealthPanel::OK, $this->row( $this->panel(), 'setup' )['state'] );
	}

	/**
	 * No tracking plugin is stated as such rather than dressed up.
	 *
	 * @return void
	 */
	public function test_it_is_honest_about_missing_tracking(): void {
		$row = $this->row( $this->panel(), 'tracking' );

		$this->assertSame( HealthPanel::NOTICE, $row['state'] );
		$this->assertStringContainsString( 'None detected', $row['value'] );
	}

	/**
	 * A store whose tracking comes from somewhere unrecognised can say so.
	 *
	 * @return void
	 */
	public function test_the_tracking_row_is_filterable(): void {
		FakeWordPress::$filters['pph_detected_tracking_plugin'][] = static function (): string {
			return 'Our courier integration';
		};

		$row = $this->row( $this->panel(), 'tracking' );

		$this->assertSame( HealthPanel::OK, $row['state'] );
		$this->assertStringContainsString( 'Our courier integration', $row['value'] );
	}

	/**
	 * No invoice plugin is stated plainly, with what happens instead.
	 *
	 * @return void
	 */
	public function test_it_is_honest_about_missing_invoices(): void {
		$row = $this->row( $this->panel(), 'invoices' );

		$this->assertStringContainsString( 'No invoice plugin detected', $row['value'] );
		$this->assertStringContainsString( 'does not generate PDFs', $row['value'] );
	}

	/**
	 * A detected invoice plugin is named, by its own name.
	 *
	 * A fresh cache per test on purpose: detection is cached, including the
	 * negative, so reading it twice in one test would be reading the first
	 * answer twice.
	 *
	 * @return void
	 */
	public function test_it_names_the_invoice_plugin(): void {
		$detector = new Detector(
			new Cache(),
			array( new FakeInvoiceProvider( 'fixture-a', true, 'https://shop.test/a.pdf' ) )
		);

		$row = $this->row( $this->panel( $detector ), 'invoices' );

		$this->assertStringContainsString( 'Fixture fixture-a', $row['value'] );
	}

	/**
	 * An unscheduled cleanup is a problem, and says how to fix it.
	 *
	 * @return void
	 */
	public function test_it_reports_a_missing_cron_event(): void {
		$row = $this->row( $this->panel(), 'cron' );

		$this->assertSame( HealthPanel::PROBLEM, $row['state'] );

		FakeWordPress::$scheduled[ Activator::CLEANUP_HOOK ] = time() + HOUR_IN_SECONDS;

		$this->assertSame( HealthPanel::OK, $this->row( $this->panel(), 'cron' )['state'] );
	}

	/**
	 * A missing table is a problem rather than a silent zero.
	 *
	 * @return void
	 */
	public function test_it_reports_a_missing_table(): void {
		$GLOBALS['wpdb'] = new FakeWpdb( array() );

		$row = $this->row( $this->panel(), 'schema' );

		$this->assertSame( HealthPanel::PROBLEM, $row['state'] );
	}

	/**
	 * The panel renders one escaped row per answer, with the state on the row
	 * so a stylesheet — or a test — can find it.
	 *
	 * @return void
	 */
	public function test_it_renders_escaped_rows(): void {
		FakeWordPress::$filters['pph_detected_tracking_plugin'][] = static function (): string {
			return '<script>alert(1)</script>';
		};

		ob_start();
		$this->panel()->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-pph-health-row="tracking"', $html );
		$this->assertStringContainsString( 'data-pph-health-state=', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'alert(1)', $html );
	}
}
