<?php
/**
 * The help form's topic vocabulary.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Actions;

use PostPurchaseHub\Security\Sanitizer;

/**
 * What a customer can say their message is about.
 *
 * Split out of `Help` for the same reason `ReorderOptions` is split out of
 * `Reorder`: the action is a flow, this is configuration, and a merchant
 * filtering the list should not have to read the flow to find it. All static —
 * every method is a function of the filters and its arguments, with no service
 * to inject.
 *
 * A topic is a closed vocabulary validated server-side, never free text
 * (docs/SPEC.md Phase 8's stored-XSS control: "reasons restricted to a
 * server-side whitelist of codes"). The customer's own words go in the
 * message, which is stripped and capped instead.
 *
 * @since 0.13.0
 */
final class HelpTopics {

	/**
	 * Topics offered when nothing filters them.
	 *
	 * @var string[]
	 */
	public const DEFAULTS = array(
		'where_is_my_order',
		'item_problem',
		'change_order',
		'billing',
		'other',
	);

	/**
	 * The topic codes this install accepts.
	 *
	 * @since 0.13.0
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		/**
		 * Filters the topics a customer may choose on the help form.
		 *
		 * @since 0.13.0
		 *
		 * @param string[] $topics Accepted topic codes.
		 */
		$topics = apply_filters( 'wpmphub_help_topics', self::DEFAULTS );

		return is_array( $topics ) && array() !== $topics ? array_values( $topics ) : self::DEFAULTS;
	}

	/**
	 * Human labels for this install's topics, keyed by code.
	 *
	 * A topic a filter added without a matching label still gets one,
	 * humanised from its code, rather than an empty option in the select.
	 *
	 * @since 0.13.0
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$defaults = array(
			'where_is_my_order' => __( 'Where is my order?', 'wpmake-post-purchase-hub' ),
			'item_problem'      => __( 'Something is wrong with an item', 'wpmake-post-purchase-hub' ),
			'change_order'      => __( 'I need to change this order', 'wpmake-post-purchase-hub' ),
			'billing'           => __( 'A billing or payment question', 'wpmake-post-purchase-hub' ),
			'other'             => __( 'Something else', 'wpmake-post-purchase-hub' ),
		);

		/**
		 * Filters the labels shown for help topics.
		 *
		 * @since 0.13.0
		 *
		 * @param array<string, string> $labels Labels keyed by topic code.
		 */
		$labels = apply_filters( 'wpmphub_help_topic_labels', $defaults );
		$labels = is_array( $labels ) ? $labels : $defaults;

		$result = array();

		foreach ( self::codes() as $code ) {
			$result[ $code ] = isset( $labels[ $code ] ) && is_string( $labels[ $code ] ) && '' !== $labels[ $code ]
				? $labels[ $code ]
				: ucfirst( str_replace( '_', ' ', $code ) );
		}

		return $result;
	}

	/**
	 * Validates a candidate topic against this install's vocabulary.
	 *
	 * @since 0.13.0
	 *
	 * @param mixed $value Candidate topic code.
	 * @return string|null Null when the value is not one this install offers.
	 */
	public static function normalise( $value ): ?string {
		return Sanitizer::reason_code( $value, self::codes() );
	}

	/**
	 * The label for one code, falling back to the code itself.
	 *
	 * @since 0.13.0
	 *
	 * @param string $code Topic code.
	 * @return string
	 */
	public static function label_for( string $code ): string {
		$labels = self::labels();

		return $labels[ $code ] ?? $code;
	}
}
