<?php
/**
 * The contextual help action.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Emails\EmailSettings;
use PostPurchaseHub\Security\Sanitizer;

/**
 * A question about one order, arriving with the order already attached.
 *
 * The cheapest ticket deflection in the plugin, and the one most easily
 * mistaken for something bigger: docs/SPEC.md rules out live chat and
 * ticketing outright — "'Get Help' is a form that hands off with context. It is
 * not a helpdesk." Nothing here is stored, nothing has a lifecycle, and no row
 * is created that would then need a queue, a retention rule and an admin
 * screen. A submission becomes an email to the store and a `wpmphub_help_submitted`
 * action, and then this plugin is done with it.
 *
 * That "nothing is stored" is also why the abuse controls sit where they do:
 * with no request history to count against, there is no per-order cap or
 * cooldown to apply, and what bounds this instead is the rate limiter in
 * `Rest\HelpController` and the length cap here.
 *
 * `check()` is the single gate — the render path, the form and the REST route
 * all ask it, so a form forged into the page cannot submit what a button would
 * not have offered. Ownership is not its business:
 * `Security\OwnershipResolver` decides that, once, before any caller arrives.
 *
 * @since 0.13.0
 */
final class Help {

	/**
	 * Action id.
	 *
	 * @var string
	 */
	public const ID = 'help';

	/**
	 * Cap on the customer's message.
	 *
	 * Shorter than `Sanitizer::NOTE_MAX_LENGTH`, deliberately: a note stored
	 * against a request has a 2000-character column behind it, while this goes
	 * straight into an email. A question needing more than this is a reply to
	 * the email the merchant is about to send.
	 *
	 * @var int
	 */
	public const MESSAGE_MAX_LENGTH = 1000;

	/**
	 * Submission from a signed-in customer's account pages.
	 *
	 * @var string
	 */
	public const SOURCE_ACCOUNT = 'account';

	/**
	 * Submission from a guest holding a signed order link.
	 *
	 * @var string
	 */
	public const SOURCE_GUEST = 'guest_token';

	/**
	 * Denial code for a store with nowhere to send the question.
	 *
	 * @var string
	 */
	public const REASON_NO_DESTINATION = 'help_no_destination';

	/**
	 * Denial code for a submission whose message was empty once sanitised.
	 *
	 * @var string
	 */
	public const REASON_EMPTY_MESSAGE = 'help_empty_message';

	/**
	 * Denial code for a topic that is not one this install offers.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN_TOPIC = 'help_unknown_topic';

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param EligibilityResolver $eligibility Eligibility engine, for the `wpmphub_action_eligibility` filter.
	 * @param HelpContextBuilder  $contexts    Assembles the order context a submission carries.
	 */
	public function __construct( private EligibilityResolver $eligibility, private HelpContextBuilder $contexts ) {}

	/**
	 * Registers this action against the registry.
	 *
	 * @since 0.13.0
	 *
	 * @param ActionRegistry $registry Registry to register against.
	 * @return void
	 */
	public function register( ActionRegistry $registry ): void {
		$registry->register(
			self::ID,
			self::label(),
			array( 'list', 'detail' ),
			\Closure::fromCallable( array( $this, 'resolve' ) )
		);
	}

	/**
	 * Render payload for one order and context, or null when ineligible.
	 *
	 * The link is a fragment pointing at the form `Frontend\HelpView` draws on
	 * the order's own page: on the detail page that is this page, and from the
	 * orders list it is the order's page plus the fragment. Reaching the form
	 * needs no JavaScript — only submitting it does.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order   Order to resolve against.
	 * @param string    $context Context being rendered.
	 * @return array<string, string>|null
	 */
	public function resolve( \WC_Order $order, string $context ): ?array {
		if ( ! $this->check( $order )->eligible ) {
			return null;
		}

		return array(
			'name' => self::label(),
			'url'  => 'list' === $context ? self::form_url( $order ) : self::fragment( $order ),
		);
	}

	/**
	 * Whether help may currently be asked for about an order.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return EligibilityResult
	 */
	public function check( \WC_Order $order ): EligibilityResult {
		if ( ! self::has_destination() ) {
			return EligibilityResult::denied(
				self::REASON_NO_DESTINATION,
				__( 'Messages about orders are not being accepted at the moment.', 'wpmake-post-purchase-hub' )
			);
		}

		// No status, age or product-type constraint: any order a customer can
		// see is one they can ask about. The rule is still evaluated through
		// the resolver so a merchant can hang a restriction off
		// `wpmphub_action_eligibility` here exactly as on cancel and reorder.
		return $this->eligibility->resolve( self::ID, $order, new EligibilityRule() );
	}

	/**
	 * Whether a submission would reach anybody.
	 *
	 * A form that sends a customer's question nowhere is worse than no form
	 * (CLAUDE.md hard rule 19), and a merchant can switch this plugin's help
	 * email off in WooCommerce → Settings → Emails like any other. So that
	 * email's own enabled flag is the switch, and the filter is how a helpdesk
	 * integration consuming `wpmphub_help_submitted` says "send it to me instead"
	 * without the email being on.
	 *
	 * Read through `Emails\EmailSettings` rather than from the email class:
	 * this runs while an order page is being rendered, and naming a `WC_Email`
	 * subclass there is a fatal on any request that has not already booted
	 * WooCommerce's mailer.
	 *
	 * @since 0.13.0
	 *
	 * @return bool
	 */
	public static function has_destination(): bool {
		if ( EmailSettings::is_enabled( EmailSettings::HELP_REQUEST ) ) {
			return true;
		}

		/**
		 * Filters whether something other than this plugin's own email will
		 * receive a help submission.
		 *
		 * @since 0.13.0
		 *
		 * @param bool $exists Whether a destination exists. Default false.
		 */
		return (bool) apply_filters( 'wpmphub_help_destination_exists', false );
	}

	/**
	 * The order context the form shows and a submission will carry.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return HelpContext
	 */
	public function context_for( \WC_Order $order ): HelpContext {
		return $this->contexts->for_order( $order );
	}

	/**
	 * Re-checks eligibility, sanitises the submission and hands it off.
	 *
	 * The context is rebuilt here rather than accepted from the caller: the
	 * form told the customer what it would attach, and this decides what
	 * actually is attached, from the order as it is now.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order   Order the question is about.
	 * @param string    $topic   Candidate topic code.
	 * @param string    $message Candidate message, already length-checked by the caller's schema.
	 * @param string    $source  One of the SOURCE_* constants.
	 * @return HelpContext
	 * @throws IneligibleActionException When the order is ineligible, the topic unknown or the message empty.
	 */
	public function submit( \WC_Order $order, string $topic, string $message, string $source ): HelpContext {
		$clean_topic   = HelpTopics::normalise( $topic );
		$clean_message = Sanitizer::note( $message, self::MESSAGE_MAX_LENGTH );
		$refusal       = $this->refusal( $order, $clean_topic, $clean_message );

		if ( null !== $refusal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed constructor arg stored as a property, not the message; IneligibleActionException escapes its own message.
			throw new IneligibleActionException( $refusal );
		}

		$context = $this->context_for( $order )->submitted(
			(string) $clean_topic,
			HelpTopics::label_for( (string) $clean_topic ),
			$clean_message,
			self::SOURCE_ACCOUNT === $source ? self::SOURCE_ACCOUNT : self::SOURCE_GUEST,
			$order->get_edit_order_url()
		);

		/**
		 * Fires when a customer submits the help form.
		 *
		 * The hand-off docs/SPEC.md Milestone 13 asks for: a helpdesk plugin, a
		 * CRM or a merchant's own code takes the submission from here with the
		 * order context already assembled. Nothing about the submission is
		 * stored, so this is the only chance to keep it.
		 *
		 * @since 0.13.0
		 *
		 * @param HelpContext $context Submission, and the order context it carries.
		 * @param \WC_Order   $order   Order the question is about.
		 */
		do_action( 'wpmphub_help_submitted', $context, $order );

		return $context;
	}

	/**
	 * Why this submission cannot be accepted, or null when it can.
	 *
	 * Every refusal is decided here and thrown once by the caller, so a
	 * rejected submission and an ineligible order come back through the same
	 * path with the same shape.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order   $order   Order the question is about.
	 * @param string|null $topic   Topic, already validated against the whitelist, null when it failed.
	 * @param string      $message Message, already stripped and capped.
	 * @return EligibilityResult|null
	 */
	private function refusal( \WC_Order $order, ?string $topic, string $message ): ?EligibilityResult {
		$result = $this->check( $order );

		if ( ! $result->eligible ) {
			return $result;
		}

		if ( null === $topic ) {
			return EligibilityResult::denied(
				self::REASON_UNKNOWN_TOPIC,
				__( 'Please choose what your message is about.', 'wpmake-post-purchase-hub' )
			);
		}

		if ( '' === $message ) {
			return EligibilityResult::denied(
				self::REASON_EMPTY_MESSAGE,
				__( 'Please tell us what you need help with.', 'wpmake-post-purchase-hub' )
			);
		}

		return null;
	}

	/**
	 * The translated action label.
	 *
	 * @since 0.13.0
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Get help with this order', 'wpmake-post-purchase-hub' );
	}

	/**
	 * The id of the form element for one order.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order the form is about.
	 * @return string
	 */
	public static function element_id( \WC_Order $order ): string {
		return 'wpmphub-help-' . $order->get_id();
	}

	/**
	 * The same-page fragment link to that form.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order the form is about.
	 * @return string
	 */
	public static function fragment( \WC_Order $order ): string {
		return '#' . self::element_id( $order );
	}

	/**
	 * The order page's URL, with that fragment.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order the form is about.
	 * @return string
	 */
	public static function form_url( \WC_Order $order ): string {
		return $order->get_view_order_url() . self::fragment( $order );
	}
}
