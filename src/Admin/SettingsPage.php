<?php
/**
 * Settings page: WooCommerce > Subscriptions settings.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const SLUG = 'tasg-abo-settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// options.php asks for manage_options by default. Shop managers may save these settings too.
		add_filter(
			'option_page_capability_' . self::SLUG,
			static function () {
				return 'manage_woocommerce';
			}
		);
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Subscription settings', 'tasg-abo' ),
			__( 'Subscription settings', 'tasg-abo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting( self::SLUG, Settings::RENEWAL_STATUS, array( 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( self::SLUG, Settings::PAUSE_REMINDER_DAYS, array( 'sanitize_callback' => array( $this, 'sanitize_days' ) ) );
		foreach ( Settings::CUSTOMER_OPTIONS as $definition ) {
			register_setting( self::SLUG, $definition[0], array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		}

		register_setting( self::SLUG, Settings::ADMIN_ONLY, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( self::SLUG, Settings::PAYPAL_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
	}

	public function sanitize_yes_no( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	public function sanitize_days( $value ): int {
		return max( 1, (int) $value );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscription settings', 'tasg-abo' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::SLUG );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Test phase', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::ADMIN_ONLY ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::ADMIN_ONLY ) . '" value="yes"' . checked( Settings::admin_only(), true, false ) . '> ' . esc_html__( 'Only shop managers can see and use subscriptions', 'tasg-abo' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Keep this on until you tested a full cycle yourself. Customers then see nothing. Untick it to open subscriptions to everyone.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_status">' . esc_html__( 'Status of renewal orders after payment', 'tasg-abo' ) . '</label></th><td>';
		echo '<select id="tasg_status" name="' . esc_attr( Settings::RENEWAL_STATUS ) . '">';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( Settings::renewal_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Choose the status your invoice, delivery note and label automation reacts to.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_days">' . esc_html__( 'Days between pause reminders', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_days" type="number" min="1" name="' . esc_attr( Settings::PAUSE_REMINDER_DAYS ) . '" value="' . esc_attr( (string) Settings::pause_reminder_days() ) . '">';
		echo '<p class="description">' . esc_html__( 'A paused customer gets three reminders to continue or cancel. Without an answer the subscription is cancelled.', 'tasg-abo' ) . '</p></td></tr>';

		$choices = array(
			'skip'    => __( 'Skip the next order', 'tasg-abo' ),
			'pause'   => __( 'Pause for the next period', 'tasg-abo' ),
			'address' => __( 'Change the shipping address of the next order', 'tasg-abo' ),
			'plan'    => __( 'Change the plan (period)', 'tasg-abo' ),
			'payment' => __( 'Change the payment method (Stripe)', 'tasg-abo' ),
			'orders'  => __( 'See the past orders of the subscription', 'tasg-abo' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'What customers may do', 'tasg-abo' ) . '</th><td>';
		foreach ( $choices as $action => $label ) {
			$option = Settings::CUSTOMER_OPTIONS[ $action ][0];
			echo '<input type="hidden" name="' . esc_attr( $option ) . '" value="no">';
			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $option ) . '" value="yes"' . checked( Settings::customer_may( $action ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Cancelling is always possible for customers (contracts concluded online in Germany need an easy way to cancel). Continuing after a pause is always possible too.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'PayPal (experimental)', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::PAYPAL_ENABLED ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::PAYPAL_ENABLED ) . '" value="yes"' . checked( Settings::paypal_enabled(), true, false ) . '> ' . esc_html__( 'Offer PayPal for subscriptions', 'tasg-abo' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Needs the WooCommerce PayPal Payments plugin with saving of PayPal payment methods enabled, and the customer must save the method at checkout. Without a saved method the subscription is not created. Try it with a test purchase first.', 'tasg-abo' ) . '</p></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
