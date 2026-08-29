<?php
/**
 * Opt-in admin daily digest of pending requests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Emails;

use PostPurchaseHub\Install\Activator;

use PostPurchaseHub\Admin\Menu;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestQuery;
use PostPurchaseHub\Requests\RequestRepository;

/**
 * A once-a-day summary for merchants who would rather not rely on the
 * per-request `NewRequestAdmin` email or the menu bubble alone.
 *
 * Disabled by default — this is the one email in this milestone a merchant
 * has to turn on, per docs/SPEC.md's own "Admin: new request, daily digest
 * (opt-in)". Never sent when there is nothing to report, so opting in never
 * means a daily email that just says zero.
 *
 * @since 0.10.0
 */
final class AdminDigest extends AbstractEmail {

	/**
	 * Non-autoloaded option recording when a digest last actually sent.
	 *
	 * Not listed in docs/SPEC.md Phase 6's option inventory — flagged as a
	 * spec gap in the Milestone 10 report rather than silently added. It is
	 * the smallest state this feature can hold: a single scalar, no growth to
	 * bound.
	 *
	 * @var string
	 */
	public const LAST_SENT_OPTION = 'wpmphub_digest_last_sent_at';

	/**
	 * Hook of this feature's own daily cron event.
	 *
	 * A second `wp_schedule_event()` registration, deliberately separate from
	 * `Install\Activator::CLEANUP_HOOK` — the retention sweep and an opt-in
	 * mail digest are different failure domains, and coupling them means a
	 * slow digest send delays cleanup or vice versa.
	 *
	 * @var string
	 */
	public const CRON_HOOK = Activator::DIGEST_HOOK;

	/**
	 * Pending-request count as of the moment this digest was built.
	 *
	 * @var int
	 */
	private int $pending_count = 0;

	/**
	 * Requests created since the previous digest.
	 *
	 * @var int
	 */
	private int $new_count = 0;

	/**
	 * Constructor.
	 *
	 * @since 0.10.0
	 *
	 * @param RequestRepository $requests Backs the counts this digest reports.
	 */
	public function __construct( private RequestRepository $requests ) {
		$this->id             = 'wpmphub_admin_digest';
		$this->customer_email = false;
		$this->title          = __( 'Daily request digest', 'wpmake-post-purchase-hub' );
		$this->description    = __( 'An opt-in daily summary of pending and new cancellation requests. Disabled by default.', 'wpmake-post-purchase-hub' );
		$this->email_group    = 'orders';
		$this->template_html  = 'emails/admin-digest.php';
		$this->template_plain = 'emails/plain/admin-digest.php';

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] Daily request digest', 'wpmake-post-purchase-hub' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading(): string {
		return __( 'Your daily request digest', 'wpmake-post-purchase-hub' );
	}

	/**
	 * Sends today's digest if enabled and there is something to report.
	 *
	 * Called once a day from the plugin's existing daily cron event rather
	 * than a second `wp_schedule_event()` registration, per docs/SPEC.md
	 * Phase 6's single-daily-event inventory.
	 *
	 * @since 0.10.0
	 * @return bool True when a digest was actually sent.
	 */
	public function maybe_send(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$since = (string) get_option( self::LAST_SENT_OPTION, '' );

		$this->pending_count = $this->requests->count( array( 'status' => Request::STATUS_PENDING ) );
		$this->new_count     = '' === $since
			? $this->pending_count
			: $this->requests->count( array( 'created_since' => $since ) );

		if ( 0 === $this->pending_count && 0 === $this->new_count ) {
			return false;
		}

		$sent = $this->send_if_recipient();

		if ( $sent ) {
			update_option( self::LAST_SENT_OPTION, gmdate( RequestQuery::DATE_FORMAT ), false );
		}

		return $sent;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'pending_count'      => $this->pending_count,
				'new_count'          => $this->new_count,
				'queue_url'          => admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => false,
				'email'              => $this,
			),
			$this->theme_override_path(),
			$this->template_base
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'pending_count'      => $this->pending_count,
				'new_count'          => $this->new_count,
				'queue_url'          => admin_url( 'admin.php?page=' . Menu::REQUESTS_PAGE ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => true,
				'email'              => $this,
			),
			$this->theme_override_path(),
			$this->template_base
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'wpmake-post-purchase-hub' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send a daily summary of pending and new requests', 'wpmake-post-purchase-hub' ),
				'default' => 'no',
			),
		) + $this->recipient_field( (string) get_option( 'admin_email' ) ) + $this->content_fields();
	}
}
