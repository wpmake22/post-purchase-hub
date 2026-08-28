<?php
/**
 * Email boot-order regression tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Emails;

use PHPUnit\Framework\TestCase;

/**
 * Guards the white screen this plugin shipped into: `Class "WC_Email" not found
 * in src/Emails/AbstractEmail.php`, on plugin activation.
 *
 * WooCommerce includes `class-wc-email.php` inside `WC_Emails::init_emails()`,
 * reached through `WC()->mailer()` and nowhere else. Anything that names a
 * subclass of `WC_Email` before that has happened autoloads a class whose
 * parent does not exist — a fatal, not a warning. Three of this plugin's own
 * paths did exactly that: the activation scheduler and the `plugins_loaded`
 * hook wiring both read a cron hook name off `Emails\AdminDigest`, and
 * `Actions\Help` asked `Emails\HelpRequest` whether the help email was on
 * while an order page was rendering.
 *
 * Two tests, because the bug has two shapes. The static one states the rule.
 * The other runs the real early paths in a process with no WooCommerce at all,
 * which is the only place the fatal can actually be observed — this suite
 * defines a `WC_Email` stub, so inside it the bug is invisible.
 *
 * @since 0.14.1
 *
 * @covers \PostPurchaseHub\Emails\EmailSettings
 */
final class EmailBootOrderTest extends TestCase {

	/**
	 * Every class whose parent WordPress or WooCommerce loads lazily, and the
	 * only files allowed to name it.
	 *
	 * The eight email classes extend `WC_Email`, included only inside
	 * `WC_Emails::init_emails()`. `RequestListTable` extends `WP_List_Table`,
	 * which lives in `wp-admin/includes/` and is absent from every frontend
	 * request — the reason `Admin\Menu` builds it inside its page callback and
	 * nowhere earlier.
	 *
	 * @var array<string, string>
	 */
	private const LAZY_PARENTS = array(
		'AbstractEmail'    => '/src/Emails/',
		'AdminDigest'      => '/src/Emails/',
		'HelpRequest'      => '/src/Emails/',
		'NewRequestAdmin'  => '/src/Emails/',
		'RequestApproved'  => '/src/Emails/',
		'RequestDeclined'  => '/src/Emails/',
		'RequestReceived'  => '/src/Emails/',
		'SecureOrderLink'  => '/src/Emails/',
		'RequestListTable' => '/src/Admin/Menu.php',
	);

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Every shipped PHP file, both editions included.
	 *
	 * @return array<int, string>
	 */
	private function shipped_files(): array {
		$files = array( $this->root() . '/wpmake-post-purchase-hub.php', $this->root() . '/uninstall.php' );

		foreach ( array( '/src', '/free/src', '/pro/src' ) as $directory ) {
			$path = $this->root() . $directory;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * No class with a lazily-loaded parent is named outside the one place that
	 * knows when it is safe to load.
	 *
	 * `Emails\Mailer` and `Emails\EmailSettings` are deliberately not on the
	 * list: neither extends anything, which is exactly what lets the container
	 * hold one and `Actions\Help` read the other.
	 *
	 * @return void
	 */
	public function test_no_class_with_a_lazy_parent_is_named_early(): void {
		$offenders = array();

		foreach ( $this->shipped_files() as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file from this repository, not a remote URL.
			$contents = (string) file_get_contents( $path );

			// Comments explain the rule, and so must be allowed to name the classes.
			$code = (string) preg_replace( '#(/\*.*?\*/|//[^\n]*)#s', '', $contents );

			foreach ( self::LAZY_PARENTS as $class => $allowed ) {
				if ( str_contains( $path, $allowed ) || str_contains( $path, '/' . $class . '.php' ) ) {
					continue;
				}

				if ( 1 === preg_match( '/\b' . preg_quote( $class, '/' ) . '\s*::/', $code ) ) {
					$offenders[] = basename( $path ) . ' names ' . $class;
				}

				if ( 1 === preg_match( '/new\s+' . preg_quote( $class, '/' ) . '\s*\(/', $code ) ) {
					$offenders[] = basename( $path ) . ' constructs ' . $class;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These classes extend a parent WordPress or WooCommerce loads lazily, so naming one before that parent exists is a fatal, not a warning. Read what you need through Emails\\EmailSettings, or build it inside the callback that needs it:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The early paths run with no WooCommerce present, and pull in no email
	 * class on the way.
	 *
	 * @return void
	 */
	public function test_the_early_paths_run_without_woocommerce(): void {
		$harness = $this->root() . '/tests/fixtures/boot/early-paths.php';

		$this->assertFileExists( $harness );

		$output = array();
		$status = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The fatal under test can only be observed in a process without this suite's WC_Email stub.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness ) . ' 2>&1', $output, $status );

		$printed = implode( "\n", $output );

		$this->assertSame( 0, $status, "The early paths must not fatal without WooCommerce:\n" . $printed );
		$this->assertStringContainsString( 'OK', $printed );
		$this->assertStringNotContainsString( 'WC_Email', $printed );
	}
}
