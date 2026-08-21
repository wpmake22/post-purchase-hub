<?php
/**
 * Catchable stand-in for wp_die()'s real behaviour.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

/**
 * Real wp_die() halts the request; a unit test cannot observe that directly,
 * so the wp_die() shim in tests/stubs/wp-functions.php throws this instead,
 * carrying the HTTP status a caller passed via `array( 'response' => ... )`.
 *
 * @since 0.9.0
 */
final class WPDieException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 *
	 * @param string $message Message wp_die() was called with.
	 * @param int    $status  HTTP status wp_die() was called with.
	 */
	public function __construct( string $message, private int $status ) {
		parent::__construct( $message );
	}

	/**
	 * The HTTP status wp_die() was called with.
	 *
	 * @since 0.9.0
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}
}
