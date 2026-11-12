<?php
/**
 * Finds the subscription a customer who is not logged in wants to cancel.
 * The order number plus the questions chosen in the settings must match the
 * billing data of that order. A wrong guess gives no hint which part was wrong,
 * and too many guesses from one address are stopped for a while.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cancellation;

use TasgAbo\Settings;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class Verifier {

	private const MAX_TRIES = 10;
	private const WINDOW    = 15 * MINUTE_IN_SECONDS;

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	private static function try_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return 'tasg_abo_cancel_' . md5( $ip );
	}

	public static function blocked(): bool {
		return (int) get_transient( self::try_key() ) >= self::MAX_TRIES;
	}

	private static function count_try(): void {
		set_transient( self::try_key(), (int) get_transient( self::try_key() ) + 1, self::WINDOW );
	}

	/**
	 * @param string               $order_input Order number as typed.
	 * @param array<string,string> $answers     Answers to the questions, by key.
	 */
	public function find( string $order_input, array $answers ): ?object {
		self::count_try();

		$order_id = absint( preg_replace( '/\D/', '', $order_input ) );
		$sub      = $order_id > 0 ? $this->repository->find_by_order( $order_id ) : null;
		$order    = $sub ? wc_get_order( $order_id ) : null;

		if ( ! $sub || ! $order || ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			return null;
		}

		foreach ( Settings::cancel_fields() as $key ) {
			$getter = 'get_billing_' . $key;

			if ( ! method_exists( $order, $getter ) || ! self::same( $key, (string) ( $answers[ $key ] ?? '' ), (string) $order->$getter() ) ) {
				return null;
			}
		}

		return $sub;
	}

	/**
	 * Case and spaces do not matter, a phone number is compared by its digits.
	 */
	private static function same( string $key, string $typed, string $stored ): bool {
		$normalise = static function ( string $value ) use ( $key ): string {
			$value = 'phone' === $key ? preg_replace( '/\D/', '', $value ) : preg_replace( '/\s+/', '', $value );

			return mb_strtolower( (string) $value );
		};

		$typed = $normalise( $typed );

		return '' !== $typed && hash_equals( $normalise( $stored ), $typed );
	}
}
