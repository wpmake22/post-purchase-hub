<?php
/**
 * The guest-lookup flow, shared by every surface that offers it.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

use PostPurchaseHub\Support\Logger;

/**
 * One implementation of the highest-severity surface in the plugin.
 *
 * The REST route and the no-JavaScript form are adapters over this class and
 * contain no security logic of their own. Two copies of a no-oracle guarantee
 * is one copy too many.
 *
 * Three properties this class exists to hold, all from docs/SPEC.md Phase 8:
 *
 * 1. **No existence oracle.** Every processed attempt returns the identical
 *    `ACCEPTED` result with the identical message, whether the pair matched, the
 *    email was wrong, or the order number belongs to nothing. The caller is
 *    given nothing to branch on.
 * 2. **The link goes to the order, not to the submitter.** On a match, the
 *    address on the order receives it. A submitted address never does, so
 *    lookup cannot be turned into a way of reading somebody else's order.
 * 3. **A timing envelope.** A miss is cheap and a match is expensive, so the
 *    accepted path is padded to a fixed floor. Read `pad()` for what that does
 *    and does not achieve.
 *
 * @since 0.11.0
 */
final class GuestLookupService {

	/**
	 * Rate-limiter bucket shared by every dimension of this feature.
	 *
	 * @var string
	 */
	public const BUCKET = 'lookup';

	/**
	 * Attempts allowed per IP address per window.
	 *
	 * @var int
	 */
	public const IP_LIMIT = 5;

	/**
	 * Per-IP window length.
	 *
	 * @var int
	 */
	public const IP_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Attempts allowed per submitted address per window.
	 *
	 * @var int
	 */
	public const EMAIL_LIMIT = 10;

	/**
	 * Per-address window length.
	 *
	 * @var int
	 */
	public const EMAIL_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Attempts allowed across the whole store per window.
	 *
	 * @var int
	 */
	public const SITE_LIMIT = 100;

	/**
	 * Site-wide window length.
	 *
	 * @var int
	 */
	public const SITE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Fixed floor, in milliseconds, every accepted attempt is padded to.
	 *
	 * Comfortably above the cost of loading an order and its billing address on
	 * a slow shared host, so the expensive path stays inside the envelope
	 * rather than defining it.
	 *
	 * @var int
	 */
	public const TIME_FLOOR_MS = 400;

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param GuestAccess $access       Whether this store offers lookup at all.
	 * @param OrderLookup $lookup       Order-number and email matching.
	 * @param RateLimiter $rate_limiter Abuse throttling.
	 * @param Logger      $logger       Structured security events.
	 */
	public function __construct(
		private GuestAccess $access,
		private OrderLookup $lookup,
		private RateLimiter $rate_limiter,
		private Logger $logger
	) {}

	/**
	 * Processes one lookup attempt.
	 *
	 * @since 0.11.0
	 *
	 * @param string $order_number Submitted order number, already sanitised.
	 * @param string $email        Submitted billing email, already sanitised.
	 * @param string $ip           Client IP, as a rate-limiting identity only.
	 * @return LookupResult
	 */
	public function attempt( string $order_number, string $email, string $ip ): LookupResult {
		if ( ! $this->access->is_enabled() ) {
			return new LookupResult( LookupResult::DISABLED, self::unavailable_message() );
		}

		// Deliberately not padded: the refusals below are decided before any
		// order is touched, so their timing describes the request and not the
		// store's data. Padding them would also hand an attacker a way to hold
		// a PHP worker for the floor's duration with a single request.
		$stage = $this->exhausted_limit( $ip, $email );

		if ( null !== $stage ) {
			$this->log( 'throttled', $stage, $ip, $email );

			return new LookupResult( LookupResult::THROTTLED, self::throttled_message() );
		}

		$challenge = $this->challenge( $ip, $email );

		if ( null !== $challenge ) {
			$this->log( 'challenged', 'challenge', $ip, $email );

			return new LookupResult( LookupResult::CHALLENGED, $challenge );
		}

		$started = hrtime( true );

		$order = $this->lookup->find( $order_number, $email );

		if ( $order instanceof \WC_Order ) {
			$this->queue_link( $order );
		}

		$this->pad( $started );

		return new LookupResult( LookupResult::ACCEPTED, self::accepted_message() );
	}

	/**
	 * The first rate-limit dimension already spent, if any.
	 *
	 * Every dimension is incremented on every attempt regardless of outcome —
	 * a matched pair costs an attacker exactly what a miss costs, so the
	 * counters themselves cannot be probed for a signal either.
	 *
	 * @since 0.11.0
	 *
	 * @param string $ip    Client IP.
	 * @param string $email Submitted address.
	 * @return string|null Dimension name, or null while every limit has room.
	 */
	private function exhausted_limit( string $ip, string $email ): ?string {
		$allowed = array(
			'ip'    => $this->rate_limiter->allow_ip( self::BUCKET, $ip, self::IP_LIMIT, self::IP_WINDOW ),
			'email' => $this->rate_limiter->allow_email( self::BUCKET, $email, self::EMAIL_LIMIT, self::EMAIL_WINDOW ),
			'site'  => $this->rate_limiter->allow_site( self::BUCKET, self::SITE_LIMIT, self::SITE_WINDOW ),
		);

		foreach ( $allowed as $stage => $within_limit ) {
			if ( ! $within_limit ) {
				return $stage;
			}
		}

		return null;
	}

	/**
	 * Gives a bot-challenge plugin its say.
	 *
	 * @since 0.11.0
	 *
	 * @param string $ip    Client IP.
	 * @param string $email Submitted address.
	 * @return string|null The message to show when rejected, null when allowed.
	 */
	private function challenge( string $ip, string $email ): ?string {
		/**
		 * Filters whether a bot challenge rejects this lookup attempt.
		 *
		 * The integration point for reCAPTCHA, Turnstile, hCaptcha or a
		 * merchant's own heuristic. Return a `WP_Error` to reject; return null
		 * to allow. No CAPTCHA is bundled with this plugin and none ever will
		 * be: they are third-party network calls, and this plugin makes no
		 * outbound HTTP requests (CLAUDE.md hard rule 7).
		 *
		 * The submitted address is passed hashed. A challenge provider needs to
		 * recognise a repeat submitter, which a stable hash does; it does not
		 * need the address, and a filter is the wrong place to hand one out.
		 *
		 * @since 0.11.0
		 *
		 * @param \WP_Error|null $rejection  Null to allow the attempt.
		 * @param array          $attempt    Attempt context: `ip` and `email_hash`.
		 */
		$rejection = apply_filters(
			'wpmphub_lookup_challenge',
			null,
			array(
				'ip'         => $ip,
				'email_hash' => Sanitizer::hash_email( $email ),
			)
		);

		if ( ! is_wp_error( $rejection ) ) {
			return null;
		}

		$message = (string) $rejection->get_error_message();

		return '' !== $message ? $message : self::throttled_message();
	}

	/**
	 * Arranges for the order's own address to receive a signed link.
	 *
	 * Deferred to `shutdown` rather than sent inline, and this is a security
	 * measure rather than a performance one: `wp_mail()` on a store using SMTP
	 * costs anything from twenty milliseconds to several seconds, and that cost
	 * is paid only when the pair matched. Inside the timed section it would be
	 * the loudest possible existence oracle — no amount of padding hides a
	 * two-second SMTP handshake.
	 *
	 * @since 0.11.0
	 *
	 * @param \WC_Order $order Order whose address receives the link.
	 * @return void
	 */
	private function queue_link( \WC_Order $order ): void {
		add_action(
			'shutdown',
			static function () use ( $order ): void {
				/**
				 * Filters whether the response is flushed before the link email is sent.
				 *
				 * With FastCGI the connection can be closed first, which is what
				 * keeps mail delivery out of the response's timing envelope
				 * altogether. A store whose stack misbehaves when a request
				 * finishes early can turn this off and accept that the envelope
				 * then includes however long its mail transport takes.
				 *
				 * @since 0.11.0
				 *
				 * @param bool $finish Whether to close the connection first.
				 */
				if ( function_exists( 'fastcgi_finish_request' ) && apply_filters( 'wpmphub_lookup_finish_request', true ) ) {
					fastcgi_finish_request();
				}

				/**
				 * Fires when a verified guest lookup has earned a secure order link.
				 *
				 * Emails\SecureOrderLink listens for this and sends to the
				 * address stored on the order. A listener added here must do
				 * the same: sending anywhere else, in particular to an address
				 * that arrived in the request, turns lookup into a way to read
				 * somebody else's order.
				 *
				 * @since 0.11.0
				 *
				 * @param \WC_Order $order Order the link is for.
				 */
				do_action( 'wpmphub_secure_link_requested', $order );
			},
			PHP_INT_MAX - 1
		);
	}

	/**
	 * Holds an accepted attempt open until the fixed floor has elapsed.
	 *
	 * What this achieves: the difference between "no such order number" (two
	 * filters and nothing else) and "matched, link queued" (an order loaded
	 * from the database) disappears under a floor that both finish well inside.
	 *
	 * What it does not achieve: constant time. PHP has no way to make one
	 * database query indistinguishable from none if the query is slow enough to
	 * overrun the floor, and an overrun is logged rather than hidden precisely
	 * so a store where that happens can be found. The site-wide limit of 100
	 * attempts an hour is the second half of this control: a statistical timing
	 * attack needs sample sizes this endpoint will not give up.
	 *
	 * @since 0.11.0
	 *
	 * @param int|float $started Value hrtime( true ) returned at the top of the timed section.
	 * @return void
	 */
	private function pad( $started ): void {
		/**
		 * Filters the fixed floor, in milliseconds, every accepted lookup is padded to.
		 *
		 * Raise it on a slow host where the overrun warning appears in the log;
		 * a floor below the real cost of loading an order is a floor that
		 * leaks. Lowering it below single-digit milliseconds disables the
		 * padding in all but name.
		 *
		 * @since 0.11.0
		 *
		 * @param int $milliseconds Floor in milliseconds.
		 */
		$floor_ms = (int) apply_filters( 'wpmphub_lookup_time_floor_ms', self::TIME_FLOOR_MS );
		$floor_ns = max( 0, $floor_ms ) * 1000000;
		$elapsed  = (int) ( hrtime( true ) - $started );

		if ( $elapsed >= $floor_ns ) {
			$this->logger->warning(
				'Guest lookup overran its timing floor; success and failure are no longer indistinguishable by duration.',
				array(
					'event'      => 'wpmphub.lookup.floor_overrun',
					'elapsed_ms' => (int) round( $elapsed / 1000000 ),
					'floor_ms'   => $floor_ms,
				)
			);

			return;
		}

		usleep( (int) ( ( $floor_ns - $elapsed ) / 1000 ) );
	}

	/**
	 * Writes a structured security event.
	 *
	 * The address is hashed, never logged (docs/SPEC.md Phase 8: emails hashed
	 * in logs), and so is the IP — a log a merchant ships to a third-party
	 * service should not be the place this plugin leaks who tried to look up an
	 * order.
	 *
	 * @since 0.11.0
	 *
	 * @param string $event Event suffix.
	 * @param string $stage Which control fired.
	 * @param string $ip    Client IP.
	 * @param string $email Submitted address.
	 * @return void
	 */
	private function log( string $event, string $stage, string $ip, string $email ): void {
		$this->logger->warning(
			'Guest lookup attempt refused.',
			array(
				'event'      => 'wpmphub.lookup.' . $event,
				'stage'      => $stage,
				'ip_hash'    => substr( hash( 'sha256', $ip ), 0, 16 ),
				'email_hash' => substr( Sanitizer::hash_email( $email ), 0, 16 ),
			)
		);
	}

	/**
	 * The one message every processed attempt returns.
	 *
	 * @since 0.11.0
	 *
	 * @return string
	 */
	public static function accepted_message(): string {
		return __( 'If that order exists, we have emailed a secure link to the address on file for it. The link opens the order without a password.', 'wpmake-post-purchase-hub' );
	}

	/**
	 * The message a throttled attempt returns.
	 *
	 * @since 0.11.0
	 *
	 * @return string
	 */
	public static function throttled_message(): string {
		return __( 'Too many attempts. Please wait a few minutes and try again.', 'wpmake-post-purchase-hub' );
	}

	/**
	 * The message returned when this store does not offer lookup.
	 *
	 * @since 0.11.0
	 *
	 * @return string
	 */
	public static function unavailable_message(): string {
		return __( 'Order lookup is not available on this store.', 'wpmake-post-purchase-hub' );
	}
}
