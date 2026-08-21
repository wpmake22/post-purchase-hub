<?php
/**
 * Registers this plugin's WC_Email classes.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Requests\RequestRepository;
use PostPurchaseHub\Security\TokenService;

/**
 * Wires every email this plugin ships into WooCommerce's own email system.
 *
 * Registration goes through `woocommerce_email_classes` — the same filter
 * WooCommerce's own emails, and any third-party plugin's, use — so merchants
 * find these six emails in WooCommerce → Settings → Emails, get the
 * customiser and the block email editor for free, and never see a
 * plugin-specific settings screen for something Woo already has one for.
 *
 * The list itself is built behind `pph_registered_emails` rather than
 * hardcoded here, per docs/EDITIONS.md's extension-point table: that filter
 * is what lets a Pro build add its own return-lifecycle emails without this
 * class knowing Pro exists (CLAUDE.md hard rule 17).
 *
 * @since 0.10.0
 */
final class Mailer {

	/**
	 * Lazily-built digest instance, held so the same object both registers
	 * with WooCommerce and receives the daily cron trigger — two counts
	 * computed once, not twice.
	 *
	 * @var AdminDigest|null
	 */
	private ?AdminDigest $admin_digest = null;

	/**
	 * Constructor.
	 *
	 * @since 0.10.0
	 *
	 * @param RequestRepository $requests Backs the admin digest's counts.
	 * @param TokenService      $tokens   Issues the tokens secure links carry.
	 */
	public function __construct( private RequestRepository $requests, private TokenService $tokens ) {}

	/**
	 * Wires email registration and the opt-in link injector.
	 *
	 * @since 0.10.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );

		( new LinkInjector( $this->tokens ) )->register();
	}

	/**
	 * Filter callback for `woocommerce_email_classes`.
	 *
	 * @since 0.10.0
	 *
	 * @param array<string, \WC_Email> $classes WooCommerce's own emails, keyed by class name.
	 * @return array<string, \WC_Email>
	 */
	public function register_email_classes( array $classes ): array {
		foreach ( $this->emails() as $class => $instance ) {
			$classes[ $class ] = $instance;
		}

		return $classes;
	}

	/**
	 * The admin digest instance, for the cron callback that triggers it.
	 *
	 * @since 0.10.0
	 * @return AdminDigest
	 */
	public function admin_digest(): AdminDigest {
		if ( null === $this->admin_digest ) {
			$this->admin_digest = new AdminDigest( $this->requests );
		}

		return $this->admin_digest;
	}

	/**
	 * This plugin's own emails, keyed by class name, filterable.
	 *
	 * @since 0.10.0
	 * @return array<string, \WC_Email>
	 */
	private function emails(): array {
		$defaults = array(
			RequestReceived::class => new RequestReceived(),
			RequestApproved::class => new RequestApproved(),
			RequestDeclined::class => new RequestDeclined(),
			NewRequestAdmin::class => new NewRequestAdmin(),
			SecureOrderLink::class => new SecureOrderLink( $this->tokens ),
			AdminDigest::class     => $this->admin_digest(),
		);

		/**
		 * Filters the WC_Email instances this plugin registers.
		 *
		 * Pro's extension point for its own return-lifecycle emails
		 * (docs/EDITIONS.md): add to this array rather than reaching into
		 * `woocommerce_email_classes` directly, so a future core change to how
		 * this plugin registers its own emails does not also break Pro's.
		 *
		 * @since 0.10.0
		 *
		 * @param array<string, \WC_Email> $emails Emails to register, keyed by class name.
		 */
		$emails = apply_filters( 'pph_registered_emails', $defaults );

		return is_array( $emails ) ? $emails : $defaults;
	}
}
