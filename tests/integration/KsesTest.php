<?php
/**
 * The allowed-HTML list, against the real wp_kses().
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Integration;

use PostPurchaseHub\Security\Kses;

/**
 * An allowlist that quietly ate a form control would not fail a unit test; it
 * would ship a storefront with a cancellation form that cannot be submitted.
 *
 * So this runs core's own `wp_kses()` — not a stub of it — over a document
 * carrying every element and attribute the templates emit, and fails if
 * anything is missing afterwards. The document is the specification: adding
 * markup to a template means adding it here, and finding out immediately
 * whether the allowlist covers it.
 *
 * @since 1.0.0
 *
 * @covers \PostPurchaseHub\Security\Kses
 */
final class KsesTest extends \WP_UnitTestCase {

	/**
	 * Every tag and attribute `templates/` renders, in one document.
	 *
	 * @var string
	 */
	private const EVERYTHING = <<<'HTML'
<section class="wpmphub-timeline" data-wpmphub-timeline aria-labelledby="t1" role="region">
<h2 id="t1">Order</h2><h3>Stage</h3>
<article class="a"><div class="b"><span class="c" data-wpmphub-stage-label>x</span></div></article>
<ol data-wpmphub-timeline-stages><li data-wpmphub-stage data-wpmphub-stage-state="done">
<time datetime="2026-08-28">28 Aug</time><strong>Shipped</strong> <mark>new</mark></li></ol>
<ul data-wpmphub-actions-list><li>
<a href="https://example.com" rel="nofollow" target="_blank" data-wpmphub-action-link>Invoice</a></li></ul>
<p class="wpmphub-orders__empty" data-wpmphub-orders-empty>Sign in</p>
<details open><summary>More</summary><p>Body</p></details>
<div aria-live="polite" data-wpmphub-reorder-outcome hidden>Done</div>
<div role="dialog" aria-modal="true" aria-describedby="d1" data-wpmphub-request-modal hidden>
<form class="wpmphub-modal__form" action="/x" method="post" data-wpmphub-request-form novalidate>
<fieldset><legend>Reason</legend>
<label for="r">Reason</label>
<select id="r" name="reason" required data-wpmphub-request-reason autocomplete="off">
<optgroup label="g"><option value="a" selected>A</option><option value="b" disabled>B</option></optgroup>
</select>
<input type="hidden" name="order_id" value="12" data-wpmphub-request-order-id />
<input type="text" name="number" id="n" value="" required maxlength="64" autocomplete="off"
 placeholder="p" inputmode="numeric" data-wpmphub-lookup-number />
<input type="email" name="email" id="e" autocomplete="email" data-wpmphub-lookup-email />
<input type="radio" name="mode" value="all" checked data-wpmphub-reorder-mode />
<input type="number" name="qty" min="1" max="9" step="1" size="3" pattern="[0-9]*" />
<textarea name="note" id="note" rows="4" maxlength="500" placeholder="n" data-wpmphub-request-note></textarea>
<button type="submit" name="go" value="1" class="button" data-wpmphub-request-submit>Send</button>
</fieldset>
</form>
</div>
</section>
HTML;

	/**
	 * Resets the memoised list, since a test may filter it.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'wpmphub_allowed_html' );
		Kses::reset();

		parent::tear_down();
	}

	/**
	 * The tags and attributes a fragment of markup carries.
	 *
	 * @param string $html Markup.
	 * @return array<string, array<string, bool>>
	 */
	private function inventory( string $html ): array {
		$found = array();

		if ( ! preg_match_all( '/<([a-z][a-z0-9]*)((?:\s+[^<>]*)?)>/i', $html, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}

		foreach ( $matches as $tag ) {
			$name           = strtolower( $tag[1] );
			$found[ $name ] = $found[ $name ] ?? array();

			if ( preg_match_all( '/([a-zA-Z-]+)(?==|[\s\/>])/', $tag[2], $attrs ) ) {
				foreach ( $attrs[1] as $attr ) {
					$found[ $name ][ strtolower( $attr ) ] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Nothing the templates render is stripped.
	 *
	 * @return void
	 */
	public function test_the_allowlist_keeps_every_tag_and_attribute_the_templates_render(): void {
		$before = $this->inventory( self::EVERYTHING );
		$after  = $this->inventory( Kses::filter( self::EVERYTHING ) );

		$lost = array();

		foreach ( $before as $tag => $attributes ) {
			if ( ! isset( $after[ $tag ] ) ) {
				$lost[] = '<' . $tag . '>';
				continue;
			}

			foreach ( array_keys( $attributes ) as $attribute ) {
				if ( ! isset( $after[ $tag ][ $attribute ] ) ) {
					$lost[] = $tag . '[' . $attribute . ']';
				}
			}
		}

		$this->assertSame( array(), $lost, 'The allowlist strips markup the templates render: ' . implode( ', ', $lost ) );
	}

	/**
	 * Filtering twice changes nothing, which is what lets the block callbacks
	 * escape output a shortcode callback already escaped.
	 *
	 * @return void
	 */
	public function test_filtering_is_idempotent(): void {
		$once = Kses::filter( self::EVERYTHING );

		$this->assertSame( $once, Kses::filter( $once ) );
	}

	/**
	 * It is still an escaper. A list this permissive is worth nothing if it
	 * lets a script tag or an event handler through.
	 *
	 * @return void
	 */
	public function test_it_still_removes_what_an_escaper_must(): void {
		$filtered = Kses::filter(
			'<div onclick="steal()"><script>alert(1)</script><iframe src="//evil"></iframe>'
			. '<a href="javascript:alert(1)">x</a><p>kept</p></div>'
		);

		$this->assertStringNotContainsString( '<script', $filtered );
		$this->assertStringNotContainsString( '<iframe', $filtered );
		$this->assertStringNotContainsString( 'onclick', $filtered );
		$this->assertStringNotContainsString( 'javascript:', $filtered );
		$this->assertStringContainsString( '<p>kept</p>', $filtered );
	}

	/**
	 * An empty string never reaches wp_kses(), so a block that renders nothing
	 * stays cheap.
	 *
	 * @return void
	 */
	public function test_empty_markup_is_returned_untouched(): void {
		$this->assertSame( '', Kses::filter( '' ) );
	}

	/**
	 * A theme can widen the list for markup of its own.
	 *
	 * @return void
	 */
	public function test_the_allowlist_is_filterable(): void {
		Kses::reset();

		add_filter(
			'wpmphub_allowed_html',
			static function ( $allowed ) {
				$allowed['dialog'] = array( 'open' => true );

				return $allowed;
			}
		);

		$this->assertStringContainsString( '<dialog open>', Kses::filter( '<dialog open>x</dialog>' ) );
	}
}
