<?php
/**
 * The allowed-HTML list for markup this plugin returns to WordPress.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Security;

/**
 * A last pass over markup that leaves this plugin as a return value.
 *
 * Templates escape at every point of output already — hard rule 5, and nothing
 * here relaxes it. This exists for the other half of the problem: a shortcode
 * or a block render callback hands WordPress a finished string, and WordPress
 * prints it. A reader of that callback cannot see the escaping, because it
 * happened inside an output buffer several files away, and neither can a static
 * analyser. WordPress.org's review flagged exactly that on both block callbacks.
 *
 * So the boundary escapes again. The pass is idempotent and cheap relative to
 * the order queries that produced the markup, and it means the guarantee at the
 * callback is local rather than inherited from somewhere out of sight.
 *
 * The list is core's post allowlist plus the four form elements core drops from
 * it, because this plugin's rendered surfaces include a cancellation form, a
 * help form, a reorder form and the guest lookup form. Everything the templates
 * emit is covered by `tests/integration/KsesTest.php`, which runs the real
 * `wp_kses()` — not a stub of it — against a document containing every tag and
 * attribute the templates use. An allowlist that silently ate a form control
 * would be a broken storefront rather than a caught bug, so that test treats
 * its document as the specification: markup added to a template belongs in it.
 *
 * @since 1.0.0
 */
final class Kses {

	/**
	 * Attributes every element this plugin renders may carry.
	 *
	 * `data-*` is a wildcard kses understands natively; the ARIA attributes are
	 * not, and have to be named one by one.
	 *
	 * @var array<string, bool>
	 */
	private const COMMON_ATTRIBUTES = array(
		'class'            => true,
		'id'               => true,
		'hidden'           => true,
		'title'            => true,
		'role'             => true,
		'style'            => true,
		'data-*'           => true,
		'aria-describedby' => true,
		'aria-hidden'      => true,
		'aria-label'       => true,
		'aria-labelledby'  => true,
		'aria-live'        => true,
		'aria-modal'       => true,
	);

	/**
	 * The form elements core's post allowlist does not carry, with the
	 * attributes this plugin's four forms actually use.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const FORM_ELEMENTS = array(
		'form'     => array(
			'action'     => true,
			'method'     => true,
			'novalidate' => true,
			'name'       => true,
			'target'     => true,
			'enctype'    => true,
		),
		'input'    => array(
			'type'         => true,
			'name'         => true,
			'value'        => true,
			'checked'      => true,
			'required'     => true,
			'disabled'     => true,
			'readonly'     => true,
			'placeholder'  => true,
			'maxlength'    => true,
			'minlength'    => true,
			'min'          => true,
			'max'          => true,
			'step'         => true,
			'size'         => true,
			'pattern'      => true,
			'autocomplete' => true,
			'inputmode'    => true,
		),
		'select'   => array(
			'name'         => true,
			'required'     => true,
			'disabled'     => true,
			'multiple'     => true,
			'size'         => true,
			'autocomplete' => true,
		),
		'option'   => array(
			'value'    => true,
			'selected' => true,
			'disabled' => true,
			'label'    => true,
		),
		'optgroup' => array(
			'label'    => true,
			'disabled' => true,
		),
	);

	/**
	 * Attributes core allows on elements this plugin renders, but not the ones
	 * it renders them with.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const EXTRA_ATTRIBUTES = array(
		'textarea' => array(
			'name'         => true,
			'rows'         => true,
			'cols'         => true,
			'maxlength'    => true,
			'minlength'    => true,
			'required'     => true,
			'disabled'     => true,
			'readonly'     => true,
			'placeholder'  => true,
			'autocomplete' => true,
		),
		'button'   => array(
			'type'     => true,
			'name'     => true,
			'value'    => true,
			'disabled' => true,
		),
		'label'    => array( 'for' => true ),
		'time'     => array( 'datetime' => true ),
		'details'  => array( 'open' => true ),
		'a'        => array(
			'href'     => true,
			'target'   => true,
			'rel'      => true,
			'download' => true,
		),
	);

	/**
	 * The allowed-HTML list, built once per request.
	 *
	 * @var array<string, array<string, bool>>|null
	 */
	private static ?array $allowed = null;

	/**
	 * Escapes markup this plugin is about to hand back to WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	public static function filter( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		return wp_kses( $html, self::allowed() );
	}

	/**
	 * The allowed-HTML list.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		if ( null !== self::$allowed ) {
			return self::$allowed;
		}

		$allowed = wp_kses_allowed_html( 'post' );
		$allowed = is_array( $allowed ) ? $allowed : array();

		foreach ( self::FORM_ELEMENTS as $element => $attributes ) {
			$allowed[ $element ] = array_merge(
				is_array( $allowed[ $element ] ?? null ) ? $allowed[ $element ] : array(),
				$attributes
			);
		}

		foreach ( self::EXTRA_ATTRIBUTES as $element => $attributes ) {
			$allowed[ $element ] = array_merge(
				is_array( $allowed[ $element ] ?? null ) ? $allowed[ $element ] : array(),
				$attributes
			);
		}

		foreach ( $allowed as $element => $attributes ) {
			if ( is_array( $attributes ) ) {
				$allowed[ $element ] = array_merge( $attributes, self::COMMON_ATTRIBUTES );
			}
		}

		/**
		 * Filters the allowed-HTML list this plugin escapes its own output with.
		 *
		 * A theme or an integration that renders extra markup through one of
		 * this plugin's templates needs a way to keep it, rather than watching
		 * it disappear with no error.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, bool>> $allowed Allowed elements and attributes.
		 */
		$filtered = apply_filters( 'wpmphub_allowed_html', $allowed );

		self::$allowed = is_array( $filtered ) ? $filtered : $allowed;

		return self::$allowed;
	}

	/**
	 * Drops the memoised list. Tests change the filter between cases.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function reset(): void {
		self::$allowed = null;
	}
}
