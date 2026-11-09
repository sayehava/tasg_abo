<?php
/**
 * Checkout rules for carts that contain a subscription item.
 *
 * A subscription needs an account (to keep the saved payment method) and a
 * gateway that can charge it later. Normal carts are not touched.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Checkout;

defined( 'ABSPATH' ) || exit;

final class CheckoutRules {

	public function register(): void {
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'require_account' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( $this, 'allow_signup' ) );
		add_filter( 'wc_stripe_force_save_payment_method', array( $this, 'force_save_card' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_gateways' ) );
	}

	public static function cart_has_subscription(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['tasg_abo_interval'] ) ) {
				return true;
			}
		}

		return false;
	}

	public function require_account( $required ) {
		return self::cart_has_subscription() ? true : $required;
	}

	/**
	 * Make sure the "create an account" part of checkout is switched on,
	 * the same standard WooCommerce form that guests already know.
	 */
	public function allow_signup( $value ) {
		return self::cart_has_subscription() ? 'yes' : $value;
	}

	public function force_save_card( $force ) {
		return self::cart_has_subscription() ? true : $force;
	}

	/**
	 * Only gateways that can be charged again later are offered.
	 * PayPal joins this list once its renewal charge exists.
	 */
	public function limit_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::cart_has_subscription() ) {
			return $gateways;
		}

		$allowed = (array) apply_filters( 'tasg_abo_supported_gateway_prefixes', array( 'stripe' ) );

		foreach ( array_keys( $gateways ) as $id ) {
			$ok = false;
			foreach ( $allowed as $prefix ) {
				if ( 0 === strpos( (string) $id, $prefix ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	}
}
