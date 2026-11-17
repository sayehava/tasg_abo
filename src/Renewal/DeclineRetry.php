<?php
/**
 * Retries after a declined renewal payment.
 *
 * A declined payment (card expired, not enough money, the bank wants the customer
 * to confirm) does not end the subscription at once. It stays active, the customer
 * is asked to check the payment method, and the same order is tried again 1, 3 and
 * 5 days after it was due. Only when the last try fails is it cancelled.
 *
 * Plans shorter than a day (test plans) retry after 5, 15 and 25 minutes instead,
 * so a test does not take five days.
 *
 * The state is kept in one option per subscription (not a transient, it must
 * survive for days).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Renewal;

defined( 'ABSPATH' ) || exit;

final class DeclineRetry {

	/** Days after the due date of each retry. */
	public const DAYS = array( 1, 3, 5 );

	/** Minutes after the due date of each retry for plans shorter than a day. */
	public const TEST_MINUTES = array( 5, 15, 25 );

	private static function key( int $subscription_id ): string {
		return 'tasg_abo_decline_' . $subscription_id;
	}

	/**
	 * @return array{tries:int,retry_at:int} Tries so far and the time of the next try (0 = none).
	 */
	public static function get( int $subscription_id ): array {
		$state = get_option( self::key( $subscription_id ), array() );

		return array(
			'tries'    => is_array( $state ) ? (int) ( $state['tries'] ?? 0 ) : 0,
			'retry_at' => is_array( $state ) ? (int) ( $state['retry_at'] ?? 0 ) : 0,
		);
	}

	/**
	 * Note one more declined try. Returns the time of the next try, or 0 when all tries are used.
	 */
	public static function record( object $sub ): int {
		$tries = self::get( (int) $sub->id )['tries'] + 1;
		$steps = self::short_plan( $sub ) ? self::TEST_MINUTES : self::DAYS;

		if ( $tries > count( $steps ) ) {
			self::clear( (int) $sub->id );
			return 0;
		}

		$unit = self::short_plan( $sub ) ? MINUTE_IN_SECONDS : DAY_IN_SECONDS;
		$due  = strtotime( $sub->next_payment . ' UTC' );
		$at   = max( time() + MINUTE_IN_SECONDS, $due + $steps[ $tries - 1 ] * $unit );

		update_option(
			self::key( (int) $sub->id ),
			array(
				'tries'    => $tries,
				'retry_at' => $at,
			),
			false
		);

		return $at;
	}

	/**
	 * True while the next try is still in the future.
	 */
	public static function waiting( int $subscription_id ): bool {
		return self::get( $subscription_id )['retry_at'] > time();
	}

	/**
	 * The customer chose another payment method: try at once instead of waiting.
	 */
	public static function retry_now( int $subscription_id ): void {
		$state = self::get( $subscription_id );

		if ( $state['tries'] > 0 ) {
			update_option(
				self::key( $subscription_id ),
				array(
					'tries'    => $state['tries'],
					'retry_at' => time(),
				),
				false
			);
		}
	}

	public static function clear( int $subscription_id ): void {
		delete_option( self::key( $subscription_id ) );
	}

	private static function short_plan( object $sub ): bool {
		return in_array( (string) $sub->period_unit, array( 'minute', 'hour' ), true );
	}
}
