<?php
/**
 * Reads and writes subscription rows. Dates are stored in UTC.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public function insert( array $data ): int {
		global $wpdb;

		$data['cancel_key'] = wp_generate_password( 32, false );
		$data['created']    = current_time( 'mysql', true );

		$wpdb->insert( Schema::table(), $data );

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( Schema::table(), $data, array( 'id' => $id ) );
	}

	public function find( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE id = %d', $id ) );

		return $row ?: null;
	}

	/**
	 * The subscription an order belongs to: its first order or one of its renewal orders.
	 */
	public function find_by_order( int $order_id ): ?object {
		global $wpdb;

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$renewal_of = (int) $order->get_meta( '_tasg_abo_renewal_of' );

		if ( $renewal_of > 0 ) {
			return $this->find( $renewal_of );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE origin_order_id = %d LIMIT 1', $order_id ) );

		return $row ?: null;
	}

	public function find_by_key( string $key ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE cancel_key = %s', $key ) );

		return $row ?: null;
	}

	/**
	 * SQL part that leaves out subscriptions whose next order falls on or after the
	 * date they end at (a cancellation made inside the minimum term).
	 */
	private const NOT_ENDING = ' AND (cancel_after IS NULL OR next_payment < cancel_after)';

	/**
	 * Active subscriptions that are due and whose reminder email already went out.
	 * A subscription is never charged before the customer was warned.
	 *
	 * @return object[]
	 */
	public function due( string $until_utc ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'active' AND reminder_sent = 1 AND next_payment <= %s" . self::NOT_ENDING . " ORDER BY next_payment ASC",
				$until_utc
			)
		);
	}

	/**
	 * Active subscriptions whose reminder time has come and that were not warned yet.
	 * Each subscription has its own reminder lead time (from its plan). The margin
	 * makes the reminder go out a little early, so the notice is never shorter than promised.
	 *
	 * @return object[]
	 */
	public function needing_reminder( string $now_utc, int $margin_minutes ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'active' AND reminder_sent = 0 AND next_payment <= DATE_ADD(%s, INTERVAL (reminder_minutes + %d) MINUTE)" . self::NOT_ENDING . " ORDER BY next_payment ASC",
				$now_utc,
				$margin_minutes
			)
		);
	}

	/**
	 * Active Stripe subscriptions due within the given days that were not yet checked
	 * for an expiring card for their current due date.
	 *
	 * @return object[]
	 */
	public function needing_card_check( string $now_utc, int $days ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'active' AND gateway LIKE %s AND next_payment <= DATE_ADD(%s, INTERVAL %d DAY) AND (card_notice_for IS NULL OR card_notice_for <> next_payment)" . self::NOT_ENDING . " ORDER BY next_payment ASC",
				$wpdb->esc_like( 'stripe' ) . '%',
				$now_utc,
				$days
			)
		);
	}

	/**
	 * Active subscriptions with a cancellation inside the minimum term whose last
	 * paid period is over: no further order follows, so they can now end.
	 *
	 * @return object[]
	 */
	public function ended( string $now_utc ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'active' AND cancel_after IS NOT NULL AND next_payment >= cancel_after AND next_payment <= %s ORDER BY next_payment ASC",
				$now_utc
			)
		);
	}

	/**
	 * Paused subscriptions whose next continue-or-cancel reminder is due.
	 *
	 * @return object[]
	 */
	public function paused_due( string $until_utc ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'paused' AND next_payment <= %s ORDER BY next_payment ASC",
				$until_utc
			)
		);
	}

	/**
	 * Take ownership of a due renewal by moving it to its next date in one
	 * atomic step. Only one caller can win, so a renewal is never made twice.
	 */
	public function claim( int $id, string $new_next_utc ): bool {
		global $wpdb;

		$changed = $wpdb->update(
			Schema::table(),
			array(
				'next_payment'  => $new_next_utc,
				'reminder_sent' => 0,
			),
			array(
				'id'            => $id,
				'status'        => 'active',
				'reminder_sent' => 1,
			)
		);

		return 1 === $changed;
	}

	/**
	 * Does the customer already have a running (active or paused) subscription for this product?
	 * One that only waits for its minimum term to end still counts.
	 */
	public function has_open( int $user_id, int $product_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table() . " WHERE user_id = %d AND product_id = %d AND status IN ('active','paused') LIMIT 1",
				$user_id,
				$product_id
			)
		);
	}

	/**
	 * @return object[]
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE user_id = %d ORDER BY id DESC', $user_id )
		);
	}
}
