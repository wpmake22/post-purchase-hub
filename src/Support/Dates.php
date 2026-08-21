<?php
/**
 * Business-day date arithmetic.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Support;

/**
 * Adds business days to a moment in time.
 *
 * Exists because this math gets duplicated wrong in four places the moment
 * more than one feature needs a delivery estimate. Every method here is a
 * pure function of its arguments: no option reads, no queries, nothing
 * static and mutable. The one WordPress call in the class, `store_timezone()`,
 * only resolves a `DateTimeZone` — the arithmetic itself works on whatever
 * timezone the caller's `DateTimeImmutable` already carries.
 *
 * Addition is calendar-based, not duration-based: adding `P1D` to a
 * `DateTimeImmutable` keeps its local wall-clock time and lets PHP resolve the
 * UTC offset for the new date, which is exactly what carries a delivery
 * estimate correctly across a DST transition. A store quoting "by 4pm Monday"
 * from a Friday order means 4pm Monday *local time*, whether or not the
 * clocks moved in between.
 *
 * @since 0.5.0
 */
final class Dates {

	/**
	 * Days of the week treated as non-business days when the caller specifies none.
	 *
	 * `DateTimeInterface::format('w')` values: 0 (Sunday) through 6 (Saturday).
	 *
	 * @var array<int, int>
	 */
	public const DEFAULT_WEEKEND_DAYS = array( 0, 6 );

	/**
	 * Adds a number of business days to a moment in time.
	 *
	 * Counting starts the day *after* `$from`: `$from` is the moment something
	 * happened, not itself a day of business-day progress. Zero returns `$from`
	 * unchanged. A negative count is a programming error and is treated as zero
	 * rather than walking backwards into undefined behaviour.
	 *
	 * @since 0.5.0
	 *
	 * @param \DateTimeImmutable $from         Starting moment, in the timezone the result should keep.
	 * @param int                $business_days Number of business days to add.
	 * @param array<int, int>    $weekend_days  Non-business days of the week (0 Sunday – 6 Saturday). Defaults to Saturday/Sunday.
	 * @param array<int, string> $holidays      Non-business dates, as `Y-m-d` strings in `$from`'s timezone.
	 * @return \DateTimeImmutable
	 */
	public static function add_business_days(
		\DateTimeImmutable $from,
		int $business_days,
		array $weekend_days = self::DEFAULT_WEEKEND_DAYS,
		array $holidays = array()
	): \DateTimeImmutable {
		$remaining = max( 0, $business_days );
		$date      = $from;
		$one_day   = new \DateInterval( 'P1D' );
		$holidays  = array_flip( $holidays );

		while ( $remaining > 0 ) {
			$date = $date->add( $one_day );

			if ( self::is_business_day( $date, $weekend_days, $holidays ) ) {
				--$remaining;
			}
		}

		return $date;
	}

	/**
	 * Whether a moment falls on a business day.
	 *
	 * @since 0.5.0
	 *
	 * @param \DateTimeImmutable       $date         Moment to check, evaluated by its calendar date.
	 * @param array<int, int>          $weekend_days Non-business days of the week (0 Sunday – 6 Saturday).
	 * @param array<int|string, mixed> $holidays     Non-business dates. Either a `Y-m-d` list or a flipped (`Y-m-d` => truthy) lookup — both are accepted so callers checking one date at a time do not have to flip a list themselves.
	 * @return bool
	 */
	public static function is_business_day( \DateTimeImmutable $date, array $weekend_days, array $holidays ): bool {
		if ( in_array( (int) $date->format( 'w' ), $weekend_days, true ) ) {
			return false;
		}

		$ymd = $date->format( 'Y-m-d' );

		return ! ( isset( $holidays[ $ymd ] ) || in_array( $ymd, $holidays, true ) );
	}

	/**
	 * The store's configured timezone.
	 *
	 * The only WordPress call in this class. Guarded so the unit suite, which
	 * boots no WordPress, can still construct `DateTimeImmutable` values
	 * directly and never has to reach this method.
	 *
	 * @since 0.5.0
	 *
	 * @return \DateTimeZone
	 */
	public static function store_timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}
}
