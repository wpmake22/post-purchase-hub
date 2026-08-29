<?php
/**
 * OwnershipResolver unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Install\Activator;
use PostPurchaseHub\Security\AccessDeniedException;
use PostPurchaseHub\Security\OwnershipResolver;
use PostPurchaseHub\Security\TokenService;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers the exactly-three-identities contract of the codebase's one
 * ownership choke point.
 *
 * @since 0.6.0
 *
 * @covers \PostPurchaseHub\Security\OwnershipResolver
 */
final class OwnershipResolverTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state and installs a token secret.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
		FakeWordPress::$options[ Activator::TOKEN_SECRET_OPTION ] = 'unit-test-secret-do-not-use-in-production';
	}

	/**
	 * Builds a resolver over a real TokenService.
	 *
	 * @return OwnershipResolver
	 */
	private function resolver(): OwnershipResolver {
		return new OwnershipResolver( new TokenService() );
	}

	/**
	 * Stores a fake order the wc_get_order() shim can serve.
	 *
	 * @param int    $id          Order id.
	 * @param int    $customer_id Owning customer id.
	 * @param string $order_key   Order key.
	 * @return \WC_Order
	 */
	private function order( int $id, int $customer_id = 0, string $order_key = 'wc_order_key' ): \WC_Order {
		$order = new \WC_Order( $id );
		$order->set_customer_id( $customer_id );
		$order->set_order_key( $order_key );

		FakeWordPress::$orders[ $id ] = $order;

		return $order;
	}

	/**
	 * The order's own logged-in customer is granted access.
	 *
	 * @return void
	 */
	public function test_the_owning_customer_is_granted_access(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 5;

		$this->assertSame( 10, $this->resolver()->assertCanAccess( 10, 'test' )->get_id() );
	}

	/**
	 * A logged-in customer requesting another customer's order is rejected.
	 *
	 * @return void
	 */
	public function test_a_different_logged_in_customer_is_rejected(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id = 6;

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 10, 'test' );
	}

	/**
	 * A logged-out visitor with no token and no capability is rejected.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_with_nothing_is_rejected(): void {
		$this->order( 10, 5 );

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 10, 'test' );
	}

	/**
	 * A valid token bound to the order grants access without a login.
	 *
	 * @return void
	 */
	public function test_a_valid_token_grants_access(): void {
		$this->order( 10, 0, 'wc_order_secret' );

		$tokens = new TokenService();
		$token  = $tokens->issue( 10, 'wc_order_secret' );

		add_filter(
			'wpmphub_current_request_token',
			static function () use ( $token ): string {
				return $token;
			}
		);

		$this->assertSame( 10, $this->resolver()->assertCanAccess( 10, 'test' )->get_id() );
	}

	/**
	 * A token valid for a different order does not grant access to this one.
	 *
	 * @return void
	 */
	public function test_a_token_for_another_order_is_rejected(): void {
		$this->order( 10, 0, 'wc_order_secret' );
		$this->order( 11, 0, 'wc_order_other' );

		$tokens = new TokenService();
		$token  = $tokens->issue( 11, 'wc_order_other' );

		add_filter(
			'wpmphub_current_request_token',
			static function () use ( $token ): string {
				return $token;
			}
		);

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 10, 'test' );
	}

	/**
	 * Rotating the order key invalidates every token issued against the old one.
	 *
	 * @return void
	 */
	public function test_rotating_the_order_key_invalidates_outstanding_tokens(): void {
		$order = $this->order( 10, 0, 'wc_order_original' );

		$tokens = new TokenService();
		$token  = $tokens->issue( 10, 'wc_order_original' );

		add_filter(
			'wpmphub_current_request_token',
			static function () use ( $token ): string {
				return $token;
			}
		);

		$this->assertSame( 10, $this->resolver()->assertCanAccess( 10, 'test' )->get_id() );

		$order->set_order_key( 'wc_order_rotated' );

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 10, 'test' );
	}

	/**
	 * A staff capability grants access regardless of ownership or token.
	 *
	 * @return void
	 */
	public function test_a_capable_staff_user_is_granted_access(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id           = 99;
		FakeWordPress::$current_user_capabilities = array( OwnershipResolver::STAFF_CAPABILITY );

		$this->assertSame( 10, $this->resolver()->assertCanAccess( 10, 'test' )->get_id() );
	}

	/**
	 * A user with an unrelated capability is still rejected.
	 *
	 * @return void
	 */
	public function test_an_unrelated_capability_does_not_grant_access(): void {
		$this->order( 10, 5 );
		FakeWordPress::$current_user_id           = 99;
		FakeWordPress::$current_user_capabilities = array( 'read' );

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 10, 'test' );
	}

	/**
	 * A non-existent order is rejected exactly like a real one the requester
	 * does not own — both throw the same exception type, so a caller cannot
	 * build an existence oracle out of the difference.
	 *
	 * @return void
	 */
	public function test_a_non_existent_order_is_rejected_without_a_distinct_signal(): void {
		FakeWordPress::$current_user_id = 5;

		$this->expectException( AccessDeniedException::class );

		$this->resolver()->assertCanAccess( 404, 'test' );
	}

	/**
	 * The denial exception's reason code distinguishes cases internally, for
	 * logs and tests, even though the caller sees one exception type.
	 *
	 * @return void
	 */
	public function test_denial_reason_codes_are_stable(): void {
		$this->order( 10, 5 );

		try {
			$this->resolver()->assertCanAccess( 10, 'test' );
			$this->fail( 'Expected an AccessDeniedException.' );
		} catch ( AccessDeniedException $e ) {
			$this->assertSame( 'not_authorised', $e->reason_code );
			$this->assertSame( 10, $e->order_id );
			$this->assertSame( 'test', $e->context );
		}

		try {
			$this->resolver()->assertCanAccess( 404, 'test' );
			$this->fail( 'Expected an AccessDeniedException.' );
		} catch ( AccessDeniedException $e ) {
			$this->assertSame( 'order_not_found', $e->reason_code );
		}
	}

	/**
	 * This namespace never calls wp_set_auth_cookie — a token grants
	 * order-scoped read/action capability only, never a login. Checked across
	 * every file in Security/, not just this class, since a magic-login path
	 * introduced anywhere here would defeat the point equally.
	 *
	 * @return void
	 */
	public function test_wp_set_auth_cookie_is_not_reachable_from_this_namespace(): void {
		$reflection   = new \ReflectionClass( OwnershipResolver::class );
		$security_dir = dirname( (string) $reflection->getFileName() );
		$files        = glob( $security_dir . '/*.php' );

		$this->assertNotEmpty( $files );

		foreach ( (array) $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this plugin's own source in a test, not a remote URL.
			$source = (string) file_get_contents( $file );

			$this->assertStringNotContainsString( 'wp_set_auth_cookie', $source, "$file must never call wp_set_auth_cookie." );
		}
	}
}
