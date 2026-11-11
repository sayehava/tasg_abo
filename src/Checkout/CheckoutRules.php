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

use TasgAbo\Gateways\PayPalCharger;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class CheckoutRules {

	public function register(): void {
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'require_account' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( $this, 'allow_signup' ) );
		add_filter( 'wc_stripe_force_save_payment_method', array( $this, 'force_save_card' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_gateways' ) );
		add_filter( 'wc_stripe_payment_request_supported_types', array( $this, 'hide_express_checkout' ) );
	}

	public static function cart_has_subscription(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['tasg_abo_plan'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The Stripe express buttons (Apple Pay, Google Pay, Link, Amazon Pay) pay at once, without
	 * the account step and without our subscribe box. A subscription needs an account and a saved
	 * payment method, so the buttons are hidden for subscription carts and on the product page of a
	 * product that offers a subscription. Everyone can still pay at the normal checkout.
	 *
	 * @param array $types Product types the express buttons support.
	 */
	public function hide_express_checkout( $types ) {
		if ( self::cart_has_subscription() ) {
			return array();
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );

			if ( $product && \TasgAbo\Pricing::is_enabled( $product ) ) {
				return array();
			}
		}

		return $types;
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
	 * PayPal is added when it is switched on in the settings.
	 */
	public function limit_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::cart_has_subscription() ) {
			return $gateways;
		}

		$prefixes = Settings::paypal_enabled() ? array_merge( array( 'stripe' ), PayPalCharger::GATEWAY_IDS ) : array( 'stripe' );
		$allowed  = (array) apply_filters( 'tasg_abo_supported_gateway_prefixes', $prefixes );

		foreach ( array_keys( $gateways ) as $id ) {
			$ok = false;
			foreach ( $allowed as $prefix ) {
				if ( 0 === strpos( (string) $id, $prefix ) ) {
					$ok = true;
					break;
				}
			}
			// A payment method that cannot be charged again later is no use for a subscription.
			if ( $ok && is_object( $gateways[ $id ] ) && method_exists( $gateways[ $id ], 'is_reusable' ) && ! $gateways[ $id ]->is_reusable() ) {
				$ok = false;
			}

			if ( ! $ok ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	}
}
