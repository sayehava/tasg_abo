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
		register_setting( self::SLUG, Settings::REMINDER_HOURS, array( 'sanitize_callback' => array( $this, 'sanitize_hours' ) ) );
		register_setting( self::SLUG, Settings::PAUSE_REMINDER_DAYS, array( 'sanitize_callback' => array( $this, 'sanitize_days' ) ) );
	}

	public function sanitize_hours( $value ): int {
		return max( 48, (int) $value );
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

		echo '<tr><th scope="row"><label for="tasg_status">' . esc_html__( 'Status of renewal orders after payment', 'tasg-abo' ) . '</label></th><td>';
		echo '<select id="tasg_status" name="' . esc_attr( Settings::RENEWAL_STATUS ) . '">';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( Settings::renewal_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Choose the status your invoice, delivery note and label automation reacts to.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_hours">' . esc_html__( 'Reminder email, hours before the charge', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_hours" type="number" min="48" name="' . esc_attr( Settings::REMINDER_HOURS ) . '" value="' . esc_attr( (string) Settings::reminder_hours() ) . '">';
		echo '<p class="description">' . esc_html__( 'At least 48. Customers are never charged without this reminder.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_days">' . esc_html__( 'Days between pause reminders', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_days" type="number" min="1" name="' . esc_attr( Settings::PAUSE_REMINDER_DAYS ) . '" value="' . esc_attr( (string) Settings::pause_reminder_days() ) . '">';
		echo '<p class="description">' . esc_html__( 'A paused customer gets three reminders to continue or cancel. Without an answer the subscription is cancelled.', 'tasg-abo' ) . '</p></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
