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

	public function find_by_key( string $key ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE cancel_key = %s', $key ) );

		return $row ?: null;
	}

	/**
	 * Active subscriptions whose next payment is at or before the given UTC time.
	 *
	 * @return object[]
	 */
	public function due( string $until_utc ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table() . " WHERE status = 'active' AND next_payment <= %s ORDER BY next_payment ASC",
				$until_utc
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
