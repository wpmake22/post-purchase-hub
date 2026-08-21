<?php
/**
 * Presentation layer for merchant notes to the customer.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

/**
 * Turns an order's customer notes into the strings a template echoes.
 *
 * Only notes a merchant deliberately sent to the customer are returned;
 * WooCommerce keeps internal notes in the same place and
 * get_customer_order_notes() is what separates them.
 *
 * Nothing here interprets a note. Notes are written in the store's language and
 * merchants edit and delete them, so they are prose to be shown, never a data
 * source — the timeline is built from recorded transitions instead.
 *
 * @since 0.4.1
 */
final class OrderNotesView {

	/**
	 * Builds the display array for an order's customer notes.
	 *
	 * @since 0.4.1
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array<int, array{content: string, datetime: string, date_label: string}>
	 */
	public static function present( \WC_Order $order ): array {
		$notes = array();

		foreach ( $order->get_customer_order_notes() as $note ) {
			$added = self::timestamp( $note );

			$notes[] = array(
				'content'    => isset( $note->comment_content ) ? (string) $note->comment_content : '',
				'datetime'   => null === $added ? '' : gmdate( 'c', $added ),
				'date_label' => null === $added ? '' : (string) wp_date( self::format(), $added ),
			);
		}

		return $notes;
	}

	/**
	 * The moment a note was added, as a Unix timestamp.
	 *
	 * The GMT column is preferred because the local one is stored in whatever
	 * the site's timezone was when the note was written, which is not
	 * necessarily what it is now.
	 *
	 * @since 0.4.1
	 *
	 * @param object $note Comment row.
	 * @return int|null
	 */
	private static function timestamp( object $note ): ?int {
		$gmt = isset( $note->comment_date_gmt ) ? (string) $note->comment_date_gmt : '';

		if ( '' === $gmt ) {
			return null;
		}

		$parsed = strtotime( $gmt . ' UTC' );

		return false === $parsed ? null : $parsed;
	}

	/**
	 * The store's configured date format.
	 *
	 * @since 0.4.1
	 *
	 * @return string
	 */
	private static function format(): string {
		return (string) get_option( 'date_format', 'F j, Y' );
	}
}
