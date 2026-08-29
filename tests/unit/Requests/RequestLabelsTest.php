<?php
/**
 * Request label unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Requests\Request;
use PostPurchaseHub\Requests\RequestLabels;
use PostPurchaseHub\Tests\Unit\Support\FakeWordPress;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-functions.php';

/**
 * The admin queue was printing storage slugs at merchants.
 *
 * A reason code is `ordered_by_mistake` in the database because that is what a
 * column and a filter should hold, but the merchant reading the queue picked
 * nothing of the sort — their customer chose "I ordered this by mistake" from a
 * dropdown, and that is the sentence worth showing back.
 *
 * The interesting cases are the two vocabularies: the same `other` code means
 * something different on a cancellation than on a help message, so the type has
 * to decide which list to read.
 *
 * @since 1.0.0
 *
 * @covers \PostPurchaseHub\Requests\RequestLabels
 */
final class RequestLabelsTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeWordPress::reset();
	}

	/**
	 * A cancellation reason reads as the sentence the customer chose.
	 *
	 * @return void
	 */
	public function test_a_cancellation_reason_uses_the_customers_own_wording(): void {
		$this->assertSame(
			'I ordered this by mistake',
			RequestLabels::reason( Request::TYPE_CANCELLATION, 'ordered_by_mistake' )
		);
	}

	/**
	 * The same code means different things on different request types, so the
	 * type picks the vocabulary.
	 *
	 * @return void
	 */
	public function test_the_type_decides_which_vocabulary_is_read(): void {
		$this->assertSame( 'Other', RequestLabels::reason( Request::TYPE_CANCELLATION, 'other' ) );
		$this->assertSame( 'Something else', RequestLabels::reason( Request::TYPE_HELP, 'other' ) );
	}

	/**
	 * A help topic reads from the help vocabulary.
	 *
	 * @return void
	 */
	public function test_a_help_topic_is_labelled(): void {
		$this->assertSame(
			'Where is my order?',
			RequestLabels::reason( Request::TYPE_HELP, 'where_is_my_order' )
		);
	}

	/**
	 * A request with no reason code labels as nothing rather than as the word
	 * "null" or an empty row with a heading above it.
	 *
	 * @return void
	 */
	public function test_a_missing_reason_code_labels_as_empty(): void {
		$this->assertSame( '', RequestLabels::reason( Request::TYPE_CANCELLATION, null ) );
		$this->assertSame( '', RequestLabels::reason( Request::TYPE_CANCELLATION, '' ) );
	}

	/**
	 * A code this build has no label for still says something readable, which
	 * is what keeps a filtered or forward-dated code from rendering blank.
	 *
	 * @return void
	 */
	public function test_an_unknown_code_falls_back_to_a_readable_slug(): void {
		$this->assertSame(
			'Damaged in transit',
			RequestLabels::reason( Request::TYPE_CANCELLATION, 'damaged_in_transit' )
		);
	}

	/**
	 * Types and statuses are labelled, and unknown ones stay readable.
	 *
	 * @return void
	 */
	public function test_types_and_statuses_are_labelled(): void {
		$this->assertSame( 'Cancellation', RequestLabels::type( Request::TYPE_CANCELLATION ) );
		$this->assertSame( 'Help', RequestLabels::type( Request::TYPE_HELP ) );
		$this->assertSame( 'Pending', RequestLabels::status( Request::STATUS_PENDING ) );
		$this->assertSame( 'Withdrawn', RequestLabels::status( Request::STATUS_WITHDRAWN ) );

		$this->assertSame( 'Escalated', RequestLabels::status( 'escalated' ) );
	}

	/**
	 * Nothing here invents its own wording: relabelling a reason through the
	 * documented filter changes what the admin queue shows.
	 *
	 * @return void
	 */
	public function test_it_honours_the_documented_reason_filter(): void {
		FakeWordPress::$filters['wpmphub_cancel_reason_code_labels'][] = static function ( array $labels ): array {
			$labels['ordered_by_mistake'] = 'Bought the wrong thing';

			return $labels;
		};

		$this->assertSame(
			'Bought the wrong thing',
			RequestLabels::reason( Request::TYPE_CANCELLATION, 'ordered_by_mistake' )
		);
	}
}
