<?php
/**
 * Plugin options with their defaults. Edited on WooCommerce > Subscriptions.
 *
 * @package TasgAbo
 */

namespace TasgAbo;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const RENEWAL_STATUS      = 'tasg_abo_renewal_status';
	public const REMINDER_HOURS      = 'tasg_abo_reminder_hours';
	public const PAUSE_REMINDER_DAYS = 'tasg_abo_pause_reminder_days';
	public const PAYPAL_ENABLED      = 'tasg_abo_paypal_enabled';
	public const ADMIN_ONLY          = 'tasg_abo_admin_only';

	/** Maximum number of "continue or cancel" reminders before a paused subscription is cancelled. */
	public const PAUSE_MAX_REMINDERS = 3;

	/**
	 * Status (without the wc- prefix) a renewal order gets after successful payment.
	 * Your invoice, delivery note and DHL label automation should listen for this status.
	 */
	public static function renewal_status(): string {
		$status   = (string) get_option( self::RENEWAL_STATUS, 'processing' );
		$statuses = array_map(
			static function ( $key ) {
				return 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			},
			array_keys( wc_get_order_statuses() )
		);

		return in_array( $status, $statuses, true ) ? $status : 'processing';
	}

	/**
	 * Hours before a payment at which the reminder goes out. Never below 48.
	 */
	public static function reminder_hours(): int {
		return max( 48, (int) get_option( self::REMINDER_HOURS, 72 ) );
	}

	/**
	 * Test phase switch. While on (the default), only shop managers see and can use
	 * the subscribe option. Customers see nothing. Renewals of existing subscriptions
	 * keep running either way.
	 */
	public static function admin_only(): bool {
		return 'no' !== get_option( self::ADMIN_ONLY, 'yes' );
	}

	public static function visible_to_current_user(): bool {
		return ! self::admin_only() || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * PayPal for subscriptions is experimental and off until the owner switches it on.
	 */
	public static function paypal_enabled(): bool {
		return 'yes' === get_option( self::PAYPAL_ENABLED, 'no' );
	}

	public static function pause_reminder_days(): int {
		return max( 1, (int) get_option( self::PAUSE_REMINDER_DAYS, 7 ) );
	}
}
