<?php
/**
 * Sanitizer unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Security\Sanitizer;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * Covers email normalisation (the anti-alias-bypass rule Phase 8 asks for),
 * reason-code whitelisting, note sanitisation and the nocache() side effect.
 *
 * @since 0.6.0
 *
 * @covers \PostPurchaseHub\Security\Sanitizer
 */
final class SanitizerTest extends TestCase {

	/**
	 * Casing differences fold to the same normalised address.
	 *
	 * @return void
	 */
	public function test_normalise_email_folds_case(): void {
		$this->assertSame(
			Sanitizer::normalise_email( 'Jane.Doe@Example.com' ),
			Sanitizer::normalise_email( 'jane.doe@example.com' )
		);
	}

	/**
	 * Dots in the local part are folded out, so alias variants of one mailbox
	 * cannot each get a fresh rate-limit budget.
	 *
	 * @return void
	 */
	public function test_normalise_email_folds_dots_in_the_local_part(): void {
		$this->assertSame( 'janedoe@example.com', Sanitizer::normalise_email( 'j.a.n.e.doe@example.com' ) );
		$this->assertSame( 'janedoe@example.com', Sanitizer::normalise_email( 'jane.doe@example.com' ) );
	}

	/**
	 * A dot in the domain is left alone — only the local part is folded.
	 *
	 * @return void
	 */
	public function test_normalise_email_leaves_the_domain_alone(): void {
		$this->assertSame( 'janedoe@sub.example.co.uk', Sanitizer::normalise_email( 'jane.doe@sub.example.co.uk' ) );
	}

	/**
	 * Every alias spelling of one mailbox hashes identically.
	 *
	 * @return void
	 */
	public function test_hash_email_is_stable_across_alias_spellings(): void {
		$canonical = Sanitizer::hash_email( 'jane.doe@example.com' );

		$this->assertSame( $canonical, Sanitizer::hash_email( 'JaneDoe@Example.com' ) );
		$this->assertSame( $canonical, Sanitizer::hash_email( 'j.a.n.e.d.o.e@example.com' ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $canonical );
	}

	/**
	 * Genuinely different mailboxes never collide.
	 *
	 * @return void
	 */
	public function test_hash_email_distinguishes_different_mailboxes(): void {
		$this->assertNotSame(
			Sanitizer::hash_email( 'jane.doe@example.com' ),
			Sanitizer::hash_email( 'john.doe@example.com' )
		);
	}

	/**
	 * A code inside the whitelist is returned unchanged.
	 *
	 * @return void
	 */
	public function test_reason_code_accepts_a_whitelisted_value(): void {
		$this->assertSame( 'changed_mind', Sanitizer::reason_code( 'changed_mind', array( 'changed_mind', 'other' ) ) );
	}

	/**
	 * A code outside the whitelist, or a non-scalar, is rejected.
	 *
	 * @return void
	 */
	public function test_reason_code_rejects_anything_not_whitelisted(): void {
		$whitelist = array( 'changed_mind', 'other' );

		$this->assertNull( Sanitizer::reason_code( '<script>alert(1)</script>', $whitelist ) );
		$this->assertNull( Sanitizer::reason_code( 'CHANGED_MIND', $whitelist ) );
		$this->assertNull( Sanitizer::reason_code( array( 'changed_mind' ), $whitelist ) );
		$this->assertNull( Sanitizer::reason_code( null, $whitelist ) );
	}

	/**
	 * Markup is stripped from a note before it is stored, including a script
	 * block's content — not just its tags.
	 *
	 * @return void
	 */
	public function test_note_strips_markup(): void {
		$this->assertSame(
			'Please cancel my order',
			Sanitizer::note( '<script>alert(1)</script>Please cancel my order' )
		);
		$this->assertSame( 'bold text', Sanitizer::note( '<strong>bold</strong> text' ) );
	}

	/**
	 * A note longer than the cap is truncated, using multi-byte-safe length.
	 *
	 * @return void
	 */
	public function test_note_is_capped_at_the_configured_length(): void {
		$note = str_repeat( 'a', 2100 );

		$this->assertSame( 2000, mb_strlen( Sanitizer::note( $note ) ) );
		$this->assertSame( 5, mb_strlen( Sanitizer::note( str_repeat( 'a', 20 ), 5 ) ) );
	}

	/**
	 * A note that already fits is returned trimmed but otherwise unchanged.
	 *
	 * @return void
	 */
	public function test_note_trims_surrounding_whitespace(): void {
		$this->assertSame( 'please cancel', Sanitizer::note( "  please cancel  \n" ) );
	}

	/**
	 * Nocache() defines DONOTCACHEPAGE exactly once, and calling it again does
	 * not error even though the constant already exists.
	 *
	 * @runInSeparateProcess
	 * @return void
	 */
	public function test_nocache_defines_the_no_cache_constant(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		Sanitizer::nocache();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( constant( 'DONOTCACHEPAGE' ) );

		// Calling it again with the constant already defined must not throw
		// or warn — define() on an existing constant would.
		Sanitizer::nocache();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}
}
