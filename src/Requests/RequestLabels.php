<?php
/**
 * Human labels for the slugs a request is stored as.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

use PostPurchaseHub\Actions\Cancel;
use PostPurchaseHub\Actions\HelpTopics;

/**
 * Turns `ordered_by_mistake` back into "I ordered this by mistake".
 *
 * A request stores machine slugs — `cancellation`, `pending`,
 * `ordered_by_mistake` — because that is what a database column and a filter
 * should hold. The admin screens were printing those slugs directly, so a
 * merchant read the storage format rather than the sentence their customer
 * actually picked from a dropdown.
 *
 * The vocabulary a `reason_code` belongs to depends on the request's type: a
 * cancellation's codes come from `Actions\Cancel`, a help message's from
 * `Actions\HelpTopics`. Both are already filterable and already used to render
 * the customer-facing form, so labelling here reuses the merchant's own wording
 * rather than inventing a second set that could disagree with it.
 *
 * Anything with no label falls back to the slug made readable rather than to
 * an empty cell: a code arriving from a filter, or from a version that knew
 * about a reason this one does not, still says something.
 *
 * @since 1.0.0
 */
final class RequestLabels {

	/**
	 * The label for one request type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function type( string $type ): string {
		$labels = array(
			Request::TYPE_CANCELLATION => __( 'Cancellation', 'post-purchase-hub' ),
			Request::TYPE_RETURN       => __( 'Return', 'post-purchase-hub' ),
			Request::TYPE_HELP         => __( 'Help', 'post-purchase-hub' ),
		);

		return $labels[ $type ] ?? self::humanise( $type );
	}

	/**
	 * The label for one request status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status( string $status ): string {
		$labels = array(
			Request::STATUS_PENDING   => _x( 'Pending', 'request status', 'post-purchase-hub' ),
			Request::STATUS_APPROVED  => _x( 'Approved', 'request status', 'post-purchase-hub' ),
			Request::STATUS_DECLINED  => _x( 'Declined', 'request status', 'post-purchase-hub' ),
			Request::STATUS_WITHDRAWN => _x( 'Withdrawn', 'request status', 'post-purchase-hub' ),
			Request::STATUS_COMPLETED => _x( 'Completed', 'request status', 'post-purchase-hub' ),
		);

		return $labels[ $status ] ?? self::humanise( $status );
	}

	/**
	 * The label for a reason code, read in the vocabulary its type uses.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $type Type the code belongs to.
	 * @param string|null $code Reason code, null when the request carries none.
	 * @return string Empty string when there is no code to label.
	 */
	public static function reason( string $type, ?string $code ): string {
		if ( null === $code || '' === $code ) {
			return '';
		}

		$labels = Request::TYPE_HELP === $type ? HelpTopics::labels() : Cancel::reason_code_labels();

		return isset( $labels[ $code ] ) && '' !== $labels[ $code ]
			? (string) $labels[ $code ]
			: self::humanise( $code );
	}

	/**
	 * A slug made readable, for anything the maps above do not cover.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Slug to humanise.
	 * @return string
	 */
	private static function humanise( string $slug ): string {
		return ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
	}
}
