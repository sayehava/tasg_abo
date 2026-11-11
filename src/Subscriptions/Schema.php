<?php
/**
 * Database table for subscriptions.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Schema {

	private const VERSION = '4';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tasg_abo_subscriptions';
	}

	/**
	 * Create or update the table when the stored version is older.
	 */
	public static function maybe_install(): void {
		if ( get_option( 'tasg_abo_db_version' ) === self::VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			interval_weeks smallint(5) unsigned NOT NULL DEFAULT 0,
			period_value int(11) unsigned NOT NULL DEFAULT 1,
			period_unit varchar(10) NOT NULL DEFAULT 'week',
			reminder_minutes int(11) unsigned NOT NULL DEFAULT 2880,
			plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			plan_name varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			next_payment datetime DEFAULT NULL,
			origin_order_id bigint(20) unsigned NOT NULL,
			gateway varchar(64) NOT NULL DEFAULT '',
			last_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			address_override longtext DEFAULT NULL,
			language varchar(20) NOT NULL DEFAULT '',
			shipping_mode varchar(20) NOT NULL DEFAULT 'copy',
			shipping_until datetime DEFAULT NULL,
			skip_next tinyint(1) NOT NULL DEFAULT 0,
			pause_reminders tinyint(3) unsigned NOT NULL DEFAULT 0,
			reminder_sent tinyint(1) NOT NULL DEFAULT 0,
			cancel_key varchar(64) NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cancel_key (cancel_key),
			KEY user_id (user_id),
			KEY due (status,next_payment)
		) {$charset};"
		);

		update_option( 'tasg_abo_db_version', self::VERSION );
	}
}
