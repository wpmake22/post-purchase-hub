<?php
/**
 * What this install allows a reorder to do.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

/**
 * Every decision about reorder a merchant can change, in one place.
 *
 * Split out of `Reorder` because they are different kinds of thing: the action
 * answers "may this order be bought again, and what happens if it is", this
 * answers "what has this store decided reorder means". Keeping them together
 * put six filter-reading statics in the middle of the class that reads
 * catalogue state, and pushed it past the size this codebase allows.
 *
 * All static: every method is a function of the filters in effect, with no
 * state and nothing to inject, like `Support\Dates` and `Security\Sanitizer`.
 * When M14 gives reorder stored settings, they are read here — the callers
 * already ask this class rather than reading options themselves.
 *
 * @since 0.12.0
 */
final class ReorderOptions {

	/**
	 * Add to whatever is already in the cart.
	 *
	 * @var string
	 */
	public const MODE_MERGE = 'merge';

	/**
	 * Empty the cart first.
	 *
	 * @var string
	 */
	public const MODE_REPLACE = 'replace';

	/**
	 * Lines validated per attempt when nothing overrides it.
	 *
	 * Bounded because docs/SPEC.md Phase 4 requires it: each validated line
	 * costs a product load, and a wholesale order can carry hundreds.
	 *
	 * @var int
	 */
	public const DEFAULT_ITEM_CAP = 50;

	/**
	 * The modes a cart may be updated under.
	 *
	 * @since 0.12.0
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_MERGE, self::MODE_REPLACE );
	}

	/**
	 * Coerces a candidate mode to one the action accepts.
	 *
	 * @since 0.12.0
	 *
	 * @param string $mode Candidate mode.
	 * @return string
	 */
	public static function normalise_mode( string $mode ): string {
		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_MERGE;
	}

	/**
	 * The mode used when the customer expresses no preference.
	 *
	 * Merge, unlike core's `order_again`, which empties the cart
	 * unconditionally. A customer who was mid-shop when they clicked this did
	 * not ask to lose that, and the alternative is offered in the same screen
	 * — but a store selling configured or single-item baskets may reasonably
	 * disagree, hence the filter.
	 *
	 * @since 0.12.0
	 * @return string
	 */
	public static function default_mode(): string {
		/**
		 * Filters the cart mode a reorder uses when the customer picks neither.
		 *
		 * @since 0.12.0
		 *
		 * @param string $mode One of ReorderOptions::modes().
		 */
		return self::normalise_mode( (string) apply_filters( 'pph_reorder_default_mode', self::MODE_MERGE ) );
	}

	/**
	 * How many lines one attempt validates.
	 *
	 * @since 0.12.0
	 * @return int
	 */
	public static function item_cap(): int {
		/**
		 * Filters the number of order lines one reorder attempt validates.
		 *
		 * @since 0.12.0
		 *
		 * @param int $cap Maximum lines per attempt.
		 */
		$cap = (int) apply_filters( 'pph_reorder_item_cap', self::DEFAULT_ITEM_CAP );

		return max( 1, $cap );
	}

	/**
	 * The order statuses reorder applies to.
	 *
	 * Core's own filter is read first, so a store that has already widened
	 * `order_again` does not have to widen this separately and cannot end up
	 * with the two disagreeing. The second filter exists for the opposite
	 * case: widening this plugin's reconciled reorder without also changing
	 * core's unreconciled one everywhere else.
	 *
	 * @since 0.12.0
	 *
	 * @return string[]
	 */
	public static function allowed_statuses(): array {
		/** This filter is documented in WooCommerce: includes/wc-template-functions.php */
		$statuses = (array) apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately reads WooCommerce's own filter rather than declaring one, so this action and core agree on which statuses reorder applies to.

		/**
		 * Filters the order statuses this plugin's reorder action applies to.
		 *
		 * @since 0.12.0
		 *
		 * @param string[] $statuses Unprefixed order statuses.
		 */
		$statuses = (array) apply_filters( 'pph_reorder_allowed_statuses', array_values( $statuses ) );

		$clean = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && '' !== $status ) {
				$clean[] = str_replace( 'wc-', '', $status );
			}
		}

		return array() !== $clean ? $clean : array( 'completed' );
	}
}
