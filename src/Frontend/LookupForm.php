<?php
/**
 * The guest order-lookup form.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Security\GuestAccess;
use PostPurchaseHub\Security\Kses;
use PostPurchaseHub\Security\GuestLookupService;
use PostPurchaseHub\Security\LookupResult;
use PostPurchaseHub\Security\Sanitizer;
use PostPurchaseHub\Support\Urls;

/**
 * Registers `[wpmphub_order_lookup]` and handles its submission without JavaScript.
 *
 * The form posts to its own page and the handler redirects — post/redirect/get,
 * so a refresh does not resubmit and the submitted address never appears in
 * rendered markup. The redirect's query argument names an outcome that is the
 * same for every order (`sent`), so it is safe to bookmark, share or have a
 * page cache key on.
 *
 * `Rest\LookupController` serves the same flow for the script in
 * `assets/src/js/lookup.js`, which is a progressive enhancement over this. Both
 * are adapters over `Security\GuestLookupService`; neither decides anything.
 *
 * Nothing renders at all until a merchant has enabled guest lookup, so a store
 * that has not been through that step has no public order surface even if
 * somebody pastes the shortcode into a page (CLAUDE.md hard rule 15).
 *
 * @since 0.11.0
 */
final class LookupForm {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public const TAG = 'wpmphub_order_lookup';

	/**
	 * Field marking a POST as ours.
	 *
	 * @var string
	 */
	public const SUBMIT_FIELD = 'wpmphub_order_lookup';

	/**
	 * Order-number field name.
	 *
	 * @var string
	 */
	public const NUMBER_FIELD = 'wpmphub_order_number';

	/**
	 * Email field name.
	 *
	 * @var string
	 */
	public const EMAIL_FIELD = 'wpmphub_order_email';

	/**
	 * Query argument the redirect reports the outcome in.
	 *
	 * @var string
	 */
	public const NOTICE_PARAM = 'wpmphub_lookup';

	/**
	 * Constructor.
	 *
	 * @since 0.11.0
	 *
	 * @param GuestAccess        $access    Whether this store offers lookup at all.
	 * @param GuestLookupService $lookup    The whole flow.
	 * @param TemplateLoader     $templates Template loader.
	 */
	public function __construct(
		private GuestAccess $access,
		private GuestLookupService $lookup,
		private TemplateLoader $templates
	) {}

	/**
	 * Wires the shortcode and the submission handler.
	 *
	 * The handler is hooked early on `template_redirect` — before anything
	 * renders, because it answers with a redirect — and is keyed off its own
	 * field rather than off which page is being viewed, so a merchant who moved
	 * the markup into a theme template still gets a working form.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_submission' ), 5 );
	}

	/**
	 * Handles a submitted form, then redirects.
	 *
	 * @since 0.11.0
	 * @return void
	 */
	public function maybe_handle_submission(): void {
		$target = $this->handle_submission();

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 302 );

		exit;
	}

	/**
	 * Processes a submission and reports where to send the visitor.
	 *
	 * @since 0.11.0
	 *
	 * @return string|null Redirect target, or null when this request is not a submission.
	 */
	public function handle_submission(): ?string {
		if ( ! self::is_submission() ) {
			return null;
		}

		// A submission is never cacheable, and neither is the page it lands on:
		// both name the outcome of one visitor's attempt.
		Sanitizer::nocache();

		if ( ! $this->access->is_enabled() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberately unauthenticated; see Rest\LookupController's class docblock for why a nonce would protect nothing here, and Security\GuestLookupService for the rate limits that do.
		$number = isset( $_POST[ self::NUMBER_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NUMBER_FIELD ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$email = isset( $_POST[ self::EMAIL_FIELD ] ) ? sanitize_email( wp_unslash( $_POST[ self::EMAIL_FIELD ] ) ) : '';

		$result = $this->lookup->attempt( $number, $email, self::client_ip() );

		return add_query_arg( self::NOTICE_PARAM, $result->status, Urls::current( array( self::NOTICE_PARAM ) ) );
	}

	/**
	 * Renders the form.
	 *
	 * @since 0.11.0
	 *
	 * @param mixed $atts Shortcode attributes, none of which this shortcode takes.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		unset( $atts );

		if ( ! $this->access->is_enabled() ) {
			return '';
		}

		$notice = $this->notice();

		if ( null !== $notice ) {
			Sanitizer::nocache();
		}

		// This is a shortcode callback: WordPress prints what it returns, so the
		// markup is escaped again at the boundary. See Security\Kses.
		return Kses::filter(
			$this->templates->get(
				'lookup/form.php',
				array(
					'action' => Urls::current( array( self::NOTICE_PARAM ) ),
					'notice' => $notice,
					'fields' => array(
						'submit' => self::SUBMIT_FIELD,
						'number' => self::NUMBER_FIELD,
						'email'  => self::EMAIL_FIELD,
					),
				)
			)
		);
	}

	/**
	 * The message to show above the form, if this is a post-submission view.
	 *
	 * Every outcome is looked up from a fixed table rather than carried in the
	 * URL: a message read out of a query argument is a reflected-XSS hole and
	 * an open redirect for text, and there are only ever four outcomes.
	 *
	 * @since 0.11.0
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which of four fixed messages to show on a GET; nothing is mutated and the value is never echoed.
		$status = isset( $_GET[ self::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_PARAM ] ) ) : '';

		switch ( $status ) {
			case LookupResult::ACCEPTED:
				return array(
					'type'    => 'info',
					'message' => GuestLookupService::accepted_message(),
				);
			case LookupResult::THROTTLED:
			case LookupResult::CHALLENGED:
				return array(
					'type'    => 'error',
					'message' => GuestLookupService::throttled_message(),
				);
			case LookupResult::DISABLED:
				return array(
					'type'    => 'error',
					'message' => GuestLookupService::unavailable_message(),
				);
			default:
				return null;
		}
	}

	/**
	 * Whether this request is one of our submissions.
	 *
	 * @since 0.11.0
	 * @return bool
	 */
	private static function is_submission(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Establishing whether this POST is ours at all, before anything is read from it.
		return 'POST' === $method && isset( $_POST[ self::SUBMIT_FIELD ] );
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
