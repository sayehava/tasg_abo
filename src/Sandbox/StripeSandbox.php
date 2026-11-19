<?php
/**
 * Personal Stripe sandbox for shop managers. With the switch on, only that logged in
 * shop manager pays with the Stripe test keys; every other visitor and every live
 * order keeps the live keys. Stripe test webhooks (livemode false) are checked with
 * the test webhook secret. The orders are marked as sandbox orders (SandboxOrders).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Sandbox;

defined( 'ABSPATH' ) || exit;

final class StripeSandbox {

	/** User meta of the switch, "yes" when on. */
	public const USER_META = 'tasg_abo_stripe_sandbox';

	/** Guards the settings filter while it reads the settings itself. */
	private static $reading = false;

	/** Stripe test webhook in this request: null until checked. */
	private static $test_webhook = null;

	public function register(): void {
		add_filter( 'option_woocommerce_stripe_settings', array( $this, 'test_keys_for_sandbox' ) );
	}

	/**
	 * The Stripe plugin takes the mode from its settings. For the sandbox it gets "test
	 * mode on", only in this request, the saved settings never change.
	 *
	 * @param mixed $settings Stripe plugin settings.
	 */
	public function test_keys_for_sandbox( $settings ) {
		if ( ! is_array( $settings ) || self::$reading || ! self::active() ) {
			return $settings;
		}

		$settings['testmode'] = 'yes';

		return $settings;
	}

	/** Is this request a sandbox request (the manager's switch, or a Stripe test webhook)? */
	public static function active(): bool {
		return self::on_for_user() || self::test_webhook();
	}

	/** The current user is a shop manager with the switch on, and the test keys are there. */
	public static function on_for_user(): bool {
		if ( ! did_action( 'plugins_loaded' ) || ! function_exists( 'wp_get_current_user' ) || ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' )
			&& 'yes' === get_user_meta( get_current_user_id(), self::USER_META, true )
			&& self::has_test_keys();
	}

	/** Test publishable and secret key are filled in the Stripe plugin. */
	public static function has_test_keys(): bool {
		self::$reading = true;
		$settings      = (array) get_option( 'woocommerce_stripe_settings', array() );
		self::$reading = false;

		return '' !== trim( (string) ( $settings['test_publishable_key'] ?? '' ) ) && '' !== trim( (string) ( $settings['test_secret_key'] ?? '' ) );
	}

	/**
	 * A webhook from Stripe test mode. Live webhooks (livemode true) are never touched.
	 * The signature is still checked by the Stripe plugin, with the test secret.
	 */
	private static function test_webhook(): bool {
		if ( null === self::$test_webhook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which endpoint this is.
			$is_hook            = isset( $_GET['wc-api'] ) && 'wc_stripe' === sanitize_key( wp_unslash( $_GET['wc-api'] ) );
			$body               = $is_hook ? json_decode( (string) file_get_contents( 'php://input' ), true ) : null;
			self::$test_webhook = is_array( $body ) && array_key_exists( 'livemode', $body ) && false === $body['livemode'];
		}

		return self::$test_webhook;
	}

	/** Switch it on or off for a user. */
	public static function set( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::USER_META, 'yes' );
		} else {
			delete_user_meta( $user_id, self::USER_META );
		}
	}
}
