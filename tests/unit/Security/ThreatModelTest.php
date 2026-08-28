<?php
/**
 * Codebase-wide assertions for docs/SPEC.md Phase 8's threat table.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * The threat-table rows that are properties of the whole codebase, not of one class.
 *
 * Most of docs/SPEC.md Phase 8's fifteen rows are behavioural and are covered
 * by tests that exercise the thing — `OwnershipResolverTest` for IDOR,
 * `RequestQueryTest` for SQL injection, `TemplateLoaderTest` for path
 * traversal, `RequestActionControllerTest` for CSRF and privilege escalation.
 * The rows below cannot be covered that way, because what they assert is the
 * *absence* of a capability: there is no SSRF test to write when the plugin
 * makes no outbound request, only a check that it still makes none.
 *
 * `bin/security-gates.sh` greps for the overlapping subset in CI. This runs in
 * the unit suite as well, on the same reasoning `EditionBoundaryTest` gives:
 * a developer finds out in a second here rather than in a pipeline in ten
 * minutes, and this repository's Actions minutes are a real constraint.
 *
 * @since 0.15.0
 */
final class ThreatModelTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Every PHP file that ends up in a distribution zip.
	 *
	 * Tests are excluded deliberately: a test proving the plugin issues no
	 * refund has to be allowed to name `wc_create_refund`, and a prohibition
	 * that covered its own test could not be tested at all.
	 *
	 * @return array<string, string> Contents keyed by repository-relative path.
	 */
	private function shipped(): array {
		$files = array();

		foreach ( array( 'wpmake-post-purchase-hub.php', 'uninstall.php' ) as $file ) {
			$files[ $file ] = $this->read( $this->root() . '/' . $file );
		}

		foreach ( array( 'src', 'free', 'pro', 'templates' ) as $directory ) {
			$base = $this->root() . '/' . $directory;

			if ( ! is_dir( $base ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				$path = (string) $file->getPathname();

				if ( str_contains( $path, '/tests/' ) ) {
					continue;
				}

				$files[ substr( $path, strlen( $this->root() ) + 1 ) ] = $this->read( $path );
			}
		}

		return $files;
	}

	/**
	 * Reads a file from this repository.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function read( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this repository's own source, not a remote URL.
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Every shipped file whose source matches a pattern.
	 *
	 * @param string   $pattern Regular expression.
	 * @param string[] $exempt  Repository-relative paths allowed to match.
	 * @return array<int, string> `path:line` for each hit.
	 */
	private function hits( string $pattern, array $exempt = array() ): array {
		$found = array();

		foreach ( $this->shipped() as $path => $contents ) {
			if ( in_array( $path, $exempt, true ) ) {
				continue;
			}

			foreach ( explode( "\n", $contents ) as $number => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					$found[] = $path . ':' . ( $number + 1 );
				}
			}
		}

		return $found;
	}

	/**
	 * SSRF row: zero outbound HTTP in 1.0.
	 *
	 * No telemetry, no update pings, no remote assets — which is also what
	 * makes this plugin's WP.org security review a short conversation.
	 *
	 * @return void
	 */
	public function test_no_outbound_http_request_exists(): void {
		$this->assertSame(
			array(),
			$this->hits( '/wp_remote_|curl_init|file_get_contents\s*\(\s*[\'"]https?:/' ),
			'CLAUDE.md hard rule 7: the free plugin makes no outbound HTTP requests.'
		);
	}

	/**
	 * File-uploads row: none in 1.0.
	 *
	 * @return void
	 */
	public function test_nothing_accepts_a_file_upload(): void {
		$this->assertSame(
			array(),
			$this->hits( '/\$_FILES|wp_handle_upload|media_handle_|move_uploaded_file/' ),
			'docs/SPEC.md Phase 8: this plugin accepts no file uploads in 1.0.'
		);
	}

	/**
	 * Arbitrary-execution row: no eval, no unserialize on external input, no
	 * callable assembled from stored settings.
	 *
	 * `unserialize()` is refused outright rather than audited call by call.
	 * Everything this plugin stores round-trips through the options API or its
	 * own tables as JSON or scalars, so a call would mean a new storage habit
	 * worth stopping at the door.
	 *
	 * @return void
	 */
	public function test_nothing_executes_a_constructed_callable(): void {
		$this->assertSame(
			array(),
			$this->hits( '/\beval\s*\(|\bunserialize\s*\(|create_function|\bcall_user_func|\bassert\s*\(|\$\$/' ),
			'docs/SPEC.md Phase 8: no eval, no unserialize on external input, no dynamic callables from settings.'
		);
	}

	/**
	 * REST-permissions row: `__return_true` is never a permission callback.
	 *
	 * Matched over the whole file rather than line by line, so a hand-wrapped
	 * `'permission_callback' =>` followed by the callback on the next line is
	 * caught too.
	 *
	 * @return void
	 */
	public function test_no_route_is_open(): void {
		$offenders = array();

		foreach ( $this->shipped() as $path => $contents ) {
			if ( 1 === preg_match( '/permission_callback\'\s*=>\s*[^,\n]*__return_true/s', $contents ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'CLAUDE.md hard rule 3: every REST route declares a real permission_callback.'
		);
	}

	/**
	 * Signed tokens never become a WP session.
	 *
	 * Magic login is an account-takeover primitive, and the distance between
	 * "this visitor proved they hold a link for order 4711" and "log them in"
	 * is one convenient-looking function call.
	 *
	 * @return void
	 */
	public function test_nothing_creates_a_wordpress_session(): void {
		$this->assertSame(
			array(),
			$this->hits( '/wp_set_auth_cookie|wp_set_current_user\s*\(/' ),
			'docs/SPEC.md Phase 8: a token grants order-scoped capability, never a login.'
		);
	}

	/**
	 * No refund is issued, and no refund API is within reach of one.
	 *
	 * @return void
	 */
	public function test_no_refund_api_is_referenced(): void {
		$this->assertSame(
			array(),
			$this->hits( '/wc_create_refund|WC_Order_Refund/' ),
			'CLAUDE.md hard rule 8: 1.0 issues no refunds.'
		);
	}

	/**
	 * Reflected-XSS row, at the layer it is cheapest to hold: a template reads
	 * no request input at all.
	 *
	 * Templates receive a prepared view model (CLAUDE.md hard rule 10). A
	 * superglobal inside one is both a business-logic leak and the shape a
	 * reflected-XSS hole arrives in, so the two rules are checked at once.
	 *
	 * @return void
	 */
	public function test_no_template_reads_request_input(): void {
		$offenders = array();

		foreach ( $this->shipped() as $path => $contents ) {
			if ( ! str_starts_with( $path, 'templates/' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/', $contents ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame( array(), $offenders, 'CLAUDE.md hard rule 10: templates echo a prepared view model, never request input.' );
	}

	/**
	 * Sensitive-data row: no order-bearing route can be served from a cache.
	 *
	 * Each controller has a `test_authorise_sets_nocache` of its own, but those
	 * assert `defined( 'DONOTCACHEPAGE' )` — and a PHP constant, once defined,
	 * stays defined for the rest of the process, so whichever of them runs
	 * first makes the others vacuous. `SanitizerTest` says as much in its own
	 * docblock. This is the order-independent form: every permission callback
	 * named by a `register_rest_route()` call opens with `Sanitizer::nocache()`
	 * before it does anything else — including before it denies, since a cached
	 * 403 is still a cached fact about an order.
	 *
	 * @return void
	 */
	public function test_every_rest_permission_callback_opens_by_refusing_the_cache(): void {
		$offenders = array();
		$checked   = 0;

		foreach ( $this->shipped() as $path => $contents ) {
			if ( ! str_starts_with( $path, 'src/Rest/' ) ) {
				continue;
			}

			preg_match_all( '/\'permission_callback\'\s*=>\s*array\(\s*\$this,\s*\'([a-z_]+)\'/', $contents, $callbacks );

			foreach ( array_unique( $callbacks[1] ) as $method ) {
				++$checked;

				$pattern = '/public function ' . preg_quote( $method, '/' ) . '\([^)]*\)[^{]*\{\s*\n(?:\s*unset\([^)]*\);\s*\n)?\s*Sanitizer::nocache\(\);/';

				if ( 1 !== preg_match( $pattern, $contents ) ) {
					$offenders[] = $path . '::' . $method . '()';
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No permission callbacks were found to check — the pattern has drifted from the code.' );
		$this->assertSame( array(), $offenders, 'docs/SPEC.md Phase 8: every order-bearing response, denials included, is marked uncacheable first.' );
	}

	/**
	 * Sensitive-data row: an address reaches a log as a hash or not at all.
	 *
	 * A merchant shipping WooCommerce logs to a third-party service should not
	 * discover this plugin put customer addresses in them.
	 *
	 * @return void
	 */
	public function test_no_log_call_carries_a_raw_email(): void {
		$offenders = array();

		foreach ( $this->shipped() as $path => $contents ) {
			// The whole argument list of each logger call, which WPCS's
			// formatting rules keep on its own lines.
			if ( 1 === preg_match_all( '/logger->(?:log|debug|info|warning|error)\((.*?)\n\t*\);/s', $contents, $matches ) ) {
				foreach ( $matches[1] as $arguments ) {
					if ( 1 === preg_match( '/get_billing_email|[\'"]email[\'"]\s*=>/', $arguments ) ) {
						$offenders[] = $path;
					}
				}
			}
		}

		$this->assertSame( array(), $offenders, 'docs/SPEC.md Phase 8: emails are hashed in logs.' );
	}
}
