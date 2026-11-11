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
	public const PAUSE_REMINDER_DAYS = 'tasg_abo_pause_reminder_days';
	public const PAYPAL_ENABLED      = 'tasg_abo_paypal_enabled';
	public const ADMIN_ONLY          = 'tasg_abo_admin_only';
	public const CARD_NOTICE         = 'tasg_abo_card_notice';
	public const CARD_NOTICE_DAYS    = 'tasg_abo_card_notice_days';

	/**
	 * What customers may do themselves. Option name and default per action.
	 * Cancelling and continuing after a pause are always allowed.
	 */
	public const CUSTOMER_OPTIONS = array(
		'skip'    => array( 'tasg_abo_allow_skip', 'yes' ),
		'pause'   => array( 'tasg_abo_allow_pause', 'yes' ),
		'address' => array( 'tasg_abo_allow_address', 'yes' ),
		'plan'    => array( 'tasg_abo_allow_plan', 'no' ),
		'payment' => array( 'tasg_abo_allow_payment', 'yes' ),
		'orders'  => array( 'tasg_abo_show_orders', 'yes' ),
	);

	/** Maximum number of "continue or cancel" reminders before a paused subscription is cancelled. */
	public const PAUSE_MAX_REMINDERS = 3;

	/**
	 * May a customer do this on their own? Cancel and continue always, the rest as set by the shop owner.
	 */
	public static function customer_may( string $action ): bool {
		if ( ! isset( self::CUSTOMER_OPTIONS[ $action ] ) ) {
			return in_array( $action, array( 'cancel', 'continue' ), true );
		}

		list( $option, $default ) = self::CUSTOMER_OPTIONS[ $action ];

		return 'yes' === get_option( $option, $default );
	}

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

	/**
	 * Warn customers whose saved card expires before the next order.
	 */
	public static function card_notice(): bool {
		return 'no' !== get_option( self::CARD_NOTICE, 'yes' );
	}

	/**
	 * How many days before an order the card is checked.
	 */
	public static function card_notice_days(): int {
		return max( 2, (int) get_option( self::CARD_NOTICE_DAYS, 14 ) );
	}

	public static function pause_reminder_days(): int {
		return max( 1, (int) get_option( self::PAUSE_REMINDER_DAYS, 7 ) );
	}
}
