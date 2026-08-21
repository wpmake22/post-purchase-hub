<?php
/**
 * The order context a help submission carries.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Everything the merchant needs in order to answer without asking anything back.
 *
 * The whole point of the Get Help action, per docs/SPEC.md: "'Get Help' is a
 * form that hands off with context. It is not a helpdesk." This object *is*
 * that context — order number, status, where the order has got to, what is on
 * it — prepared once and read by three unrelated consumers: the form (which
 * shows the customer what it is about to attach), the email templates, and
 * whatever hooks `pph_help_submitted`.
 *
 * Every string here is already formatted for display, so the templates that
 * print it stay logic-free (CLAUDE.md hard rule 10) and the email escapes a
 * finished string rather than reaching back into an order. Escaping is still
 * the printer's job at the point of output, every time (hard rule 5).
 *
 * @since 0.13.0
 */
final class HelpContext {

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param int                $order_id       Order the question is about.
	 * @param string             $order_number   Order number as the customer knows it.
	 * @param string             $status         Unprefixed order status.
	 * @param string             $status_label   Translated status name.
	 * @param string             $placed_on      Formatted order date, empty when unknown.
	 * @param string             $timeline_state Label of the stage the order is sitting in, empty when it maps to none.
	 * @param array<int, string> $items     Item summary lines, already capped.
	 * @param int                $items_omitted  Items beyond the cap, not listed.
	 * @param string             $topic          Chosen topic code, empty on an unsubmitted form.
	 * @param string             $topic_label    Translated topic label, empty on an unsubmitted form.
	 * @param string             $message        The customer's message, sanitised and capped.
	 * @param string             $customer_name  Billing name, for the reply.
	 * @param string             $customer_email Billing email, for the reply.
	 * @param string             $source         How the submission reached the store: one of Help's SOURCE_* constants.
	 * @param string             $admin_url      Deep link to the order in wp-admin, for the merchant's email.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $order_number,
		public readonly string $status,
		public readonly string $status_label,
		public readonly string $placed_on,
		public readonly string $timeline_state,
		public readonly array $items,
		public readonly int $items_omitted,
		public readonly string $topic = '',
		public readonly string $topic_label = '',
		public readonly string $message = '',
		public readonly string $customer_name = '',
		public readonly string $customer_email = '',
		public readonly string $source = '',
		public readonly string $admin_url = ''
	) {}

	/**
	 * The same context with a submission's own fields filled in.
	 *
	 * Lets the read-only context the form was built from be reused for the
	 * email, so a submission cannot describe a different order than the one
	 * the customer was looking at.
	 *
	 * @since 0.13.0
	 *
	 * @param string $topic       Chosen topic code.
	 * @param string $topic_label Translated topic label.
	 * @param string $message     Sanitised message.
	 * @param string $source      One of Help's SOURCE_* constants.
	 * @param string $admin_url   Deep link to the order in wp-admin.
	 * @return self
	 */
	public function submitted( string $topic, string $topic_label, string $message, string $source, string $admin_url ): self {
		return new self(
			$this->order_id,
			$this->order_number,
			$this->status,
			$this->status_label,
			$this->placed_on,
			$this->timeline_state,
			$this->items,
			$this->items_omitted,
			$topic,
			$topic_label,
			$message,
			$this->customer_name,
			$this->customer_email,
			$source,
			$admin_url
		);
	}
}
