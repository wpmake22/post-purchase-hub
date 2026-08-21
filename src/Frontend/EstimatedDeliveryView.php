<?php
/**
 * Presentation layer for the estimated-delivery view model.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Frontend;

use PostPurchaseHub\Timeline\EstimatedDeliveryRange;

/**
 * Turns an EstimatedDeliveryRange into the strings a template echoes.
 *
 * Mirrors TimelineView for the same reason: hard rule 10 keeps templates free
 * of logic, so the null case — nothing to show — is decided here rather than
 * with an `if` a template would otherwise need.
 *
 * @since 0.5.0
 */
final class EstimatedDeliveryView {

	/**
	 * Builds the display array for one order's estimate.
	 *
	 * @since 0.5.0
	 *
	 * @param EstimatedDeliveryRange|null $eta Computed estimate, or null when there is none to show.
	 * @return array{visible: bool, start: string, end: string, label: string}
	 */
	public static function present( ?EstimatedDeliveryRange $eta ): array {
		if ( null === $eta ) {
			return array(
				'visible' => false,
				'start'   => '',
				'end'     => '',
				'label'   => '',
			);
		}

		return array(
			'visible' => true,
			'start'   => $eta->start->format( 'c' ),
			'end'     => $eta->end->format( 'c' ),
			'label'   => $eta->label,
		);
	}
}
