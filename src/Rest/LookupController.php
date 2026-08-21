<?php
/**
 * REST controller for guest order lookup.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Rest;

use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\LookupResult;
use PostPurchaseHub\Security\OrderLookup;
use PostPurchaseHub\Security\Sanitizer;

/**
 * `POST /pph/v1/lookup`.
 *
 * A thin adapter: it validates the two fields, hands them to
 * `Security\GuestLookupService` and turns the one result into the one response.
 * Every security property lives in that service, so this controller has no
 * branch on what was found — because there is nothing here to branch on.
 *
 * Three decisions worth stating, because each looks like an omission:
 *
 * **No nonce.** A logged-out WordPress nonce is derived from user id 0, so
 * anybody can mint the same value; requiring one here would add a CSRF control
 * that controls nothing while making any page carrying the form uncacheable to
 * keep the value fresh. The endpoint is safe to forge on purpose: the response
 * is identical for every outcome and a link only ever reaches the address
 * already stored on the order, so the most a forged request achieves is
 * spending a rate-limit slot. docs/SPEC.md Phase 8's CSRF row names the signed
 * token, not a nonce, as the guest-side control — and this route runs before
 * any token exists.
 *
 * **The route is not registered when guest lookup is off**, and the permission
 * callback checks the same gate again anyway. A store that has not enabled the
 * feature does not advertise the route in its REST index, and a store that
 * disables it mid-request does not serve one.
 *
 * **A throttled attempt answers 429 while everything else answers 200.** That
 * is distinguishable on purpose and leaks nothing: which rate limit fired is a
 * fact about the requester, never about an order.
 *
 * @since 0.11.0
 */
final class LookupController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'pph/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	public const ROUTE = '/lookup';

	/**
	 * Longest submitted email accepted, per RFC 5321's practical limit.
	 *
	 * @var int
	 */
	private const MAX_EMAIL_LENGTH = 254;

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param GuestAccess        $access Whether this store offers lookup at all.
	 * @param GuestLookupService $lookup The whole flow.
	 */
	public function __construct(
		private GuestAccess $access,
		private GuestLookupService $lookup
	) {}

	/**
	 * Registers the route, if this store offers lookup.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! $this->access->is_enabled() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => array( $this, 'authorise' ),
				'args'                => self::args(),
			)
		);
	}

	/**
	 * Schema for POST /lookup.
	 *
	 * @since 0.11.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function args(): array {
		return array(
			'order_number' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				},
				'validate_callback' => static function ( $value ): bool {
					if ( ! is_scalar( $value ) ) {
						return false;
					}

					$candidate = trim( (string) $value );

					return '' !== $candidate && strlen( $candidate ) <= OrderLookup::MAX_NUMBER_LENGTH;
				},
			),
			'email'        => array(
				'required'          => true,
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => static function ( $value ): string {
					return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
				},
				'validate_callback' => static function ( $value ): bool {
					if ( ! is_scalar( $value ) ) {
						return false;
					}

					$candidate = trim( (string) $value );

					return strlen( $candidate ) <= self::MAX_EMAIL_LENGTH && (bool) is_email( $candidate );
				},
			),
		);
	}

	/**
	 * Permission check for POST /lookup.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return true|\WP_Error
	 */
	public function authorise( \WP_REST_Request $request ) {
		unset( $request );

		Sanitizer::nocache();

		if ( ! $this->access->is_enabled() ) {
			return new \WP_Error(
				'pph_lookup_unavailable',
				GuestLookupService::unavailable_message(),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Processes one lookup attempt.
	 *
	 * @since 0.11.0
	 *
	 * @param \WP_REST_Request $request Request, with already-sanitised params.
	 * @return \WP_REST_Response
	 */
	public function lookup( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->lookup->attempt(
			(string) $request->get_param( 'order_number' ),
			(string) $request->get_param( 'email' ),
			self::client_ip()
		);

		// Only the message. A field reporting whether a link was actually sent
		// is exactly the existence oracle this endpoint exists to withhold, and
		// naming one here would invite a later maintainer to populate it.
		return new \WP_REST_Response( array( 'message' => $result->message ), self::status_for( $result ) );
	}

	/**
	 * The HTTP status one result answers with.
	 *
	 * @since 0.11.0
	 *
	 * @param LookupResult $result Result to map.
	 * @return int
	 */
	private static function status_for( LookupResult $result ): int {
		switch ( $result->status ) {
			case LookupResult::THROTTLED:
				return 429;
			case LookupResult::CHALLENGED:
				return 403;
			case LookupResult::DISABLED:
				return 404;
			default:
				return 200;
		}
	}

	/**
	 * The client's IP address, as a rate-limiting identity only.
	 *
	 * @since 0.11.0
	 * @return string
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
