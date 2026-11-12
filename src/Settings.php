<?php
/**
 * Plugin options with their defaults. Edited on WooCommerce > Settings > Subscriptions.
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
	public const EMAIL_DESIGNER      = 'tasg_abo_email_designer';
	public const STRIPE_ENABLED      = 'tasg_abo_stripe_enabled';
	public const STRIPE_METHODS      = 'tasg_abo_stripe_methods';
	public const BOX_TEXT            = 'tasg_abo_box_text';
	public const CART_CONFLICT       = 'tasg_abo_cart_conflict';
	public const TEST_MARKER_TEXT    = 'tasg_abo_test_marker';
	public const TEST_MARKER_NOTE    = 'tasg_abo_test_marker_note';
	public const CARD_NOTICE         = 'tasg_abo_card_notice';
	public const CARD_NOTICE_DAYS    = 'tasg_abo_card_notice_days';
	public const CANCEL_PAGE         = 'tasg_abo_cancel_page';
	public const CANCEL_FIELDS       = 'tasg_abo_cancel_fields';
	public const CANCEL_BUTTON       = 'tasg_abo_cancel_button';

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
			return in_array( $action, array( 'cancel', 'continue', 'keep' ), true );
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
	 * Offer Stripe for subscriptions (on by default).
	 */
	public static function stripe_enabled(): bool {
		return 'no' !== get_option( self::STRIPE_ENABLED, 'yes' );
	}

	/**
	 * PayPal for subscriptions is experimental and off until the owner switches it on.
	 */
	public static function paypal_enabled(): bool {
		return 'yes' === get_option( self::PAYPAL_ENABLED, 'no' );
	}

	/**
	 * Text under the subscribe checkbox, as typed (empty = the default text).
	 */
	public static function box_text_raw(): string {
		return trim( (string) get_option( self::BOX_TEXT, '' ) );
	}

	/**
	 * What happens when a subscription and other products meet in the cart:
	 * replace (the newest choice wins, the cart is arranged automatically) or block.
	 */
	public static function cart_conflict(): string {
		return 'block' === get_option( self::CART_CONFLICT, 'replace' ) ? 'block' : 'replace';
	}

	/**
	 * Text that marks test orders, for example for the invoice.
	 */
	public static function test_marker_text(): string {
		return trim( (string) get_option( self::TEST_MARKER_TEXT, 'TEST ORDER (subscription test with real data), please ignore.' ) );
	}

	/**
	 * Also write the marker into the customer note of test orders.
	 */
	public static function test_marker_in_note(): bool {
		return 'no' !== get_option( self::TEST_MARKER_NOTE, 'yes' );
	}

	/**
	 * Is YayMail installed and loaded? Only meaningful after all plugins are loaded,
	 * because YayMail may load after this plugin.
	 */
	public static function yaymail_installed(): bool {
		return class_exists( '\YayMail\Abstracts\BaseEmail' ) && defined( 'YAYMAIL_NON_ORDER_EMAILS' );
	}

	/**
	 * Who designs the subscription emails: auto (YayMail if installed, else the plugin
	 * editor), yaymail, or plugin.
	 */
	public static function email_designer(): string {
		$value = (string) get_option( self::EMAIL_DESIGNER, 'auto' );

		return in_array( $value, array( 'auto', 'yaymail', 'plugin' ), true ) ? $value : 'auto';
	}

	/**
	 * True when the emails are designed in YayMail. Choosing YayMail without YayMail
	 * installed falls back to the plugin editor, so an email is never left without a design.
	 */
	public static function use_yaymail(): bool {
		return 'plugin' !== self::email_designer() && self::yaymail_installed();
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

	/**
	 * The page with the cancellation form (shortcode [tasg_abo_cancel]), 0 if none.
	 */
	public static function cancel_page_id(): int {
		return (int) get_option( self::CANCEL_PAGE, 0 );
	}

	public static function cancel_button_raw(): string {
		return trim( (string) get_option( self::CANCEL_BUTTON, '' ) );
	}

	/**
	 * Text of the cancel buttons and links, as the owner wrote it (translatable with WPML
	 * String Translation, context Tasg Abo), or the default.
	 */
	public static function cancel_button_text(): string {
		$text = self::cancel_button_raw();

		if ( '' === $text ) {
			return __( 'Cancel contracts here', 'tasg-abo' );
		}

		return (string) apply_filters( 'wpml_translate_single_string', $text, 'Tasg Abo', 'cancel-button-text' );
	}

	/**
	 * Questions a customer who is not logged in can be asked, besides the order number.
	 * They are compared with the billing data of that order.
	 *
	 * @return array<string,string> key => label
	 */
	public static function cancel_field_choices(): array {
		return array(
			'email'      => __( 'Email address', 'tasg-abo' ),
			'first_name' => __( 'First name', 'tasg-abo' ),
			'last_name'  => __( 'Last name', 'tasg-abo' ),
			'postcode'   => __( 'Postcode', 'tasg-abo' ),
			'city'       => __( 'City', 'tasg-abo' ),
			'phone'      => __( 'Phone', 'tasg-abo' ),
		);
	}

	/**
	 * The questions that are switched on. Email address and postcode until the owner chooses.
	 *
	 * @return string[]
	 */
	public static function cancel_fields(): array {
		$saved = get_option( self::CANCEL_FIELDS, null );

		if ( ! is_array( $saved ) ) {
			return array( 'email', 'postcode' );
		}

		return array_values( array_intersect( array_keys( self::cancel_field_choices() ), $saved ) );
	}

	public static function pause_reminder_days(): int {
		return max( 1, (int) get_option( self::PAUSE_REMINDER_DAYS, 7 ) );
	}
}
