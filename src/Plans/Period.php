<?php
/**
 * Time maths and labels for subscription periods (minutes up to months).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Plans;

defined( 'ABSPATH' ) || exit;

final class Period {

	public const UNITS = array( 'minute', 'hour', 'day', 'week', 'month' );

	/**
	 * Timestamp after adding a period.
	 *
	 * Minutes, hours, days and weeks are exact lengths of time. "Every 30 days"
	 * is always 30 days later, whatever the month.
	 *
	 * Months follow the calendar and the day the subscription started on
	 * ($anchor_day): started on the 30th, it renews on the 30th of every month,
	 * and on the last day of a shorter month (28th or 29th of February), without
	 * the day drifting afterwards.
	 */
	public static function add( int $timestamp, int $value, string $unit, int $anchor_day = 0 ): int {
		$value = max( 1, $value );

		switch ( $unit ) {
			case 'minute':
				return $timestamp + $value * MINUTE_IN_SECONDS;
			case 'hour':
				return $timestamp + $value * HOUR_IN_SECONDS;
			case 'day':
				return $timestamp + $value * DAY_IN_SECONDS;
			case 'month':
				// Calendar months in the shop time zone. A day that does not exist in the
				// target month (31st to February) becomes the last day of that month.
				$date   = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
				$day    = $anchor_day > 0 ? $anchor_day : (int) $date->format( 'j' );
				$first  = $date->modify( 'first day of this month' )->modify( '+' . $value . ' months' );
				$target = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, (int) $first->format( 't' ) ) );

				return $target->getTimestamp();
		}

		return $timestamp + $value * WEEK_IN_SECONDS;
	}

	/**
	 * Day of the month the subscription started on, in the shop time zone.
	 * Monthly renewals stay on this day, or on the last day of a shorter month.
	 */
	public static function anchor_day( object $sub ): int {
		if ( empty( $sub->created ) ) {
			return 0;
		}

		return (int) ( new \DateTimeImmutable( $sub->created . ' UTC' ) )->setTimezone( wp_timezone() )->format( 'j' );
	}

	/**
	 * Rough length in seconds, for comparing a reminder lead time with a period.
	 */
	public static function seconds( int $value, string $unit ): int {
		return 'month' === $unit ? max( 1, $value ) * 30 * DAY_IN_SECONDS : self::add( 0, $value, $unit );
	}

	public static function label( int $value, string $unit ): string {
		switch ( $unit ) {
			case 'minute':
				/* translators: %d: number of minutes */
				return sprintf( _n( '%d minute', '%d minutes', $value, 'tasg-abo' ), $value );
			case 'hour':
				/* translators: %d: number of hours */
				return sprintf( _n( '%d hour', '%d hours', $value, 'tasg-abo' ), $value );
			case 'day':
				/* translators: %d: number of days */
				return sprintf( _n( '%d day', '%d days', $value, 'tasg-abo' ), $value );
			case 'month':
				/* translators: %d: number of months */
				return sprintf( _n( '%d month', '%d months', $value, 'tasg-abo' ), $value );
		}

		/* translators: %d: number of weeks */
		return sprintf( _n( '%d week', '%d weeks', $value, 'tasg-abo' ), $value );
	}

	/**
	 * Plan name with its period for a subscription row, for example "Weekly (1 week)".
	 */
	public static function row_label( object $row ): string {
		$period = self::label( (int) $row->period_value, (string) $row->period_unit );

		return '' !== (string) $row->plan_name && $period !== $row->plan_name ? $row->plan_name . ' (' . $period . ')' : $period;
	}

	/**
	 * Names for a unit drop down.
	 *
	 * @return array<string,string>
	 */
	public static function unit_names(): array {
		return array(
			'minute' => __( 'Minutes', 'tasg-abo' ),
			'hour'   => __( 'Hours', 'tasg-abo' ),
			'day'    => __( 'Days', 'tasg-abo' ),
			'week'   => __( 'Weeks', 'tasg-abo' ),
			'month'  => __( 'Months', 'tasg-abo' ),
		);
	}

	/**
	 * Minutes as the largest whole unit: 2880 becomes "2 days".
	 */
	public static function minutes_label( int $minutes ): string {
		if ( $minutes >= 1440 && 0 === $minutes % 1440 ) {
			return self::label( intdiv( $minutes, 1440 ), 'day' );
		}

		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			return self::label( intdiv( $minutes, 60 ), 'hour' );
		}

		return self::label( $minutes, 'minute' );
	}
}
