<?php
/**
 * Dates unit tests.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PostPurchaseHub\Support\Dates;

/**
 * Table-driven coverage of business-day arithmetic.
 *
 * Every expected value in {@see cases()} was derived independently of
 * `Dates::add_business_days()` — from a plain day-by-day walk written
 * separately for this purpose — and then checked against real calendar
 * facts (weekday, DST transition dates, leap years). The point of the table
 * is to catch a wrong assumption in the production algorithm, so the
 * expectations must not be produced by that same algorithm.
 *
 * @since 0.5.0
 *
 * @covers \PostPurchaseHub\Support\Dates
 */
final class DatesTest extends TestCase {

	/**
	 * Standard Saturday/Sunday weekend.
	 *
	 * @var array<int, int>
	 */
	private const WEEKEND = array( 0, 6 );

	/**
	 * A business-day addition matches its independently derived expectation.
	 *
	 * @dataProvider cases
	 *
	 * @param string             $from_string Starting moment, parseable by DateTimeImmutable.
	 * @param string             $timezone    Timezone name.
	 * @param int                $days        Business days to add.
	 * @param array<int, int>    $weekend     Non-business days of the week.
	 * @param array<int, string> $holidays    Non-business dates.
	 * @param string             $expected    Expected result, parseable by DateTimeImmutable.
	 * @return void
	 */
	public function test_add_business_days( string $from_string, string $timezone, int $days, array $weekend, array $holidays, string $expected ): void {
		$tz   = new \DateTimeZone( $timezone );
		$from = new \DateTimeImmutable( $from_string, $tz );

		$result = Dates::add_business_days( $from, $days, $weekend, $holidays );

		$this->assertSame(
			( new \DateTimeImmutable( $expected, $tz ) )->format( 'Y-m-d H:i:s P' ),
			$result->format( 'Y-m-d H:i:s P' )
		);
		$this->assertSame( $tz->getName(), $result->getTimezone()->getName(), 'The result keeps the timezone it was given.' );
	}

	/**
	 * At least 25 date cases, including DST, year and leap-year boundaries.
	 *
	 * @return array<string, array{string, string, int, array<int, int>, array<int, string>, string}>
	 */
	public static function cases(): array {
		return array(
			'Monday +1 business day'                       => array( '2026-01-05 09:00:00', 'UTC', 1, self::WEEKEND, array(), '2026-01-06 09:00:00' ),
			'Friday +1 business day skips the weekend'     => array( '2026-01-02 16:00:00', 'UTC', 1, self::WEEKEND, array(), '2026-01-05 16:00:00' ),
			'Friday +2 business days'                      => array( '2026-01-02 16:00:00', 'UTC', 2, self::WEEKEND, array(), '2026-01-06 16:00:00' ),
			'Zero days returns the same moment unchanged'  => array( '2026-01-07 12:00:00', 'UTC', 0, self::WEEKEND, array(), '2026-01-07 12:00:00' ),
			'Starting on a Saturday still counts from the next day' => array( '2026-01-03 10:00:00', 'UTC', 1, self::WEEKEND, array(), '2026-01-05 10:00:00' ),
			'Starting on a Sunday still counts from the next day' => array( '2026-01-04 10:00:00', 'UTC', 1, self::WEEKEND, array(), '2026-01-05 10:00:00' ),
			'Friday/Saturday weekend: Thursday +1'         => array( '2026-01-01 10:00:00', 'UTC', 1, array( 5, 6 ), array(), '2026-01-04 10:00:00' ),
			'Friday/Saturday weekend: Thursday +2'         => array( '2026-01-01 10:00:00', 'UTC', 2, array( 5, 6 ), array(), '2026-01-05 10:00:00' ),
			'Holiday skip: day before Christmas +1, Dec 25 a holiday' => array( '2026-12-24 09:00:00', 'UTC', 1, self::WEEKEND, array( '2026-12-25' ), '2026-12-28 09:00:00' ),
			'Holiday skip: day before Christmas +2, Dec 25 a holiday' => array( '2026-12-24 09:00:00', 'UTC', 2, self::WEEKEND, array( '2026-12-25' ), '2026-12-29 09:00:00' ),
			'Year boundary, no holiday configured'         => array( '2025-12-30 09:00:00', 'UTC', 2, self::WEEKEND, array(), '2026-01-01 09:00:00' ),
			'Year boundary, New Year\'s Day configured as a holiday' => array( '2025-12-30 09:00:00', 'UTC', 2, self::WEEKEND, array( '2026-01-01' ), '2026-01-02 09:00:00' ),
			'Leap day as the starting point, +1'           => array( '2028-02-29 09:00:00', 'UTC', 1, self::WEEKEND, array(), '2028-03-01 09:00:00' ),
			'Leap day as the starting point, +3'           => array( '2028-02-29 09:00:00', 'UTC', 3, self::WEEKEND, array(), '2028-03-03 09:00:00' ),
			'Crossing a leap day from the day before'      => array( '2028-02-28 09:00:00', 'UTC', 2, self::WEEKEND, array(), '2028-03-01 09:00:00' ),
			'A non-leap February does not roll over early' => array( '2026-02-27 09:00:00', 'UTC', 1, self::WEEKEND, array(), '2026-03-02 09:00:00' ),
			'DST spring-forward crossing, New York, +1'    => array( '2026-03-06 16:00:00', 'America/New_York', 1, self::WEEKEND, array(), '2026-03-09 16:00:00' ),
			'DST spring-forward crossing, New York, +2'    => array( '2026-03-06 16:00:00', 'America/New_York', 2, self::WEEKEND, array(), '2026-03-10 16:00:00' ),
			'DST fall-back crossing, New York, +1'         => array( '2026-10-30 16:00:00', 'America/New_York', 1, self::WEEKEND, array(), '2026-11-02 16:00:00' ),
			'DST fall-back crossing, New York, +2'         => array( '2026-10-30 16:00:00', 'America/New_York', 2, self::WEEKEND, array(), '2026-11-03 16:00:00' ),
			'Non-UTC, non-server timezone: handling time leg' => array( '2026-01-02 16:00:00', 'Asia/Kathmandu', 2, self::WEEKEND, array(), '2026-01-06 16:00:00' ),
			'Non-UTC, non-server timezone: transit-min leg' => array( '2026-01-06 16:00:00', 'Asia/Kathmandu', 3, self::WEEKEND, array(), '2026-01-09 16:00:00' ),
			'Non-UTC, non-server timezone: transit-max leg' => array( '2026-01-06 16:00:00', 'Asia/Kathmandu', 5, self::WEEKEND, array(), '2026-01-13 16:00:00' ),
			'Consecutive holidays are all skipped'         => array( '2026-04-01 09:00:00', 'UTC', 1, self::WEEKEND, array( '2026-04-02', '2026-04-03' ), '2026-04-06 09:00:00' ),
			'A longer addition spans two weekends'         => array( '2026-01-05 09:00:00', 'UTC', 10, self::WEEKEND, array(), '2026-01-19 09:00:00' ),
			'A weekend of one day only'                    => array( '2026-01-03 09:00:00', 'UTC', 1, array( 0 ), array(), '2026-01-05 09:00:00' ),
			'An empty weekend list treats every day as a business day' => array( '2026-01-02 09:00:00', 'UTC', 1, array(), array(), '2026-01-03 09:00:00' ),
			'Southern-hemisphere timezone, no DST at this date' => array( '2026-06-05 16:00:00', 'Australia/Sydney', 1, self::WEEKEND, array(), '2026-06-08 16:00:00' ),
			'Half-hour UTC offset timezone'                => array( '2026-01-02 16:00:00', 'Asia/Kolkata', 2, self::WEEKEND, array(), '2026-01-06 16:00:00' ),
		);
	}

	/**
	 * A negative day count is treated as zero rather than walking backwards.
	 *
	 * @return void
	 */
	public function test_a_negative_count_is_treated_as_zero(): void {
		$from = new \DateTimeImmutable( '2026-01-07 12:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame(
			$from->format( 'Y-m-d H:i:s' ),
			Dates::add_business_days( $from, -3 )->format( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * The default weekend is Saturday and Sunday when the caller specifies none.
	 *
	 * @return void
	 */
	public function test_the_default_weekend_is_saturday_and_sunday(): void {
		// Friday, so +1 with the default weekend must land on Monday.
		$from = new \DateTimeImmutable( '2026-01-02 09:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-01-05', Dates::add_business_days( $from, 1 )->format( 'Y-m-d' ) );
	}

	/**
	 * A holiday check accepts a plain list as well as a flipped lookup.
	 *
	 * @return void
	 */
	public function test_is_business_day_accepts_either_holiday_shape(): void {
		$date = new \DateTimeImmutable( '2026-12-25 09:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertFalse( Dates::is_business_day( $date, self::WEEKEND, array( '2026-12-25' ) ) );
		$this->assertFalse( Dates::is_business_day( $date, self::WEEKEND, array( '2026-12-25' => true ) ) );
		$this->assertTrue( Dates::is_business_day( $date, self::WEEKEND, array( '2026-12-24' ) ) );
	}

	/**
	 * A weekend day is never a business day even off the holiday list.
	 *
	 * @return void
	 */
	public function test_a_weekend_day_is_never_a_business_day(): void {
		$saturday = new \DateTimeImmutable( '2026-01-03 09:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertFalse( Dates::is_business_day( $saturday, self::WEEKEND, array() ) );
	}

	/**
	 * The store timezone falls back to UTC when wp_timezone() is unavailable.
	 *
	 * The unit suite boots no WordPress, so wp_timezone() never exists here —
	 * this asserts the guard rather than the WordPress function itself.
	 *
	 * @return void
	 */
	public function test_store_timezone_falls_back_to_utc_without_wordpress(): void {
		$this->assertSame( 'UTC', Dates::store_timezone()->getName() );
	}
}
