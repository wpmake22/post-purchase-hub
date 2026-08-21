<?php
/**
 * Assembles the order context a help submission carries.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Timeline\TimelineBuilder;

/**
 * Turns one order into the summary a merchant can answer from.
 *
 * The only place that reads an order for help purposes, so the form and the
 * email cannot describe the same order differently — the form shows exactly
 * what the submission will attach because both come from here.
 *
 * Reads the timeline through `Timeline\TimelineBuilder` rather than mapping
 * statuses to stages again: "where has this order got to" already has one
 * answer in this codebase, and a second one that drifts would show the
 * customer one state and the merchant another.
 *
 * @since 0.13.0
 */
final class HelpContextBuilder {

	/**
	 * Most line items ever listed in the summary.
	 *
	 * A cap rather than the whole order (CLAUDE.md hard rule 12): the summary
	 * grows with the order, and an email is not the place for a 300-line
	 * inventory. Anything past the cap is counted, not listed.
	 *
	 * @var int
	 */
	public const ITEM_CAP = 20;

	/**
	 * Constructor.
	 *
	 * @since 0.13.0
	 *
	 * @param TimelineBuilder $timeline Supplies the stage the order is sitting in.
	 */
	public function __construct( private TimelineBuilder $timeline ) {}

	/**
	 * The context for one order, with no submission in it yet.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return HelpContext
	 */
	public function for_order( \WC_Order $order ): HelpContext {
		list( $items, $omitted ) = self::item_lines( $order );

		return new HelpContext(
			$order->get_id(),
			$order->get_order_number(),
			$order->get_status(),
			wc_get_order_status_name( $order->get_status() ),
			self::placed_on( $order ),
			$this->timeline_state( $order ),
			$items,
			$omitted,
			'',
			'',
			'',
			$order->get_formatted_billing_full_name(),
			$order->get_billing_email()
		);
	}

	/**
	 * The stage the order is sitting in, as a label.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return string Empty when the status maps to no stage at all.
	 */
	private function timeline_state( \WC_Order $order ): string {
		$current = $this->timeline->build( $order )->current();

		return null === $current ? '' : $current->label;
	}

	/**
	 * The order's date, in the site's own date format.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to describe.
	 * @return string Empty when the order carries no creation date.
	 */
	private static function placed_on( \WC_Order $order ): string {
		$created = $order->get_date_created();

		if ( ! $created instanceof \WC_DateTime ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $created->getTimestamp() );
	}

	/**
	 * The item summary lines, capped, and the number of items left off.
	 *
	 * @since 0.13.0
	 *
	 * @param \WC_Order $order Order to summarise.
	 * @return array{0: array<int, string>, 1: int}
	 */
	private static function item_lines( \WC_Order $order ): array {
		$lines   = array();
		$omitted = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( count( $lines ) >= self::ITEM_CAP ) {
				++$omitted;
				continue;
			}

			$lines[] = sprintf(
				/* translators: 1: quantity ordered, 2: product name. */
				_x( '%1$d × %2$s', 'order item summary line', 'post-purchase-hub' ),
				$item->get_quantity(),
				$item->get_name()
			);
		}

		return array( $lines, $omitted );
	}
}
