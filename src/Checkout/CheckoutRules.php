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
use TasgAbo\Gateways\StripeCharger;
use TasgAbo\Gateways\StripeMethods;
use TasgAbo\Log;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class CheckoutRules {

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'block_second_subscription' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'require_account' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( $this, 'allow_signup' ) );
		add_filter( 'wc_stripe_force_save_payment_method', array( $this, 'force_save_card' ), 10, 2 );
		add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'save_for_renewals' ), 30, 2 );
		add_filter( 'woocommerce_package_rates', array( $this, 'free_shipping_rates' ), 100, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_gateways' ) );
		add_filter( 'wc_stripe_payment_request_supported_types', array( $this, 'hide_express_checkout' ) );
		add_filter( 'wc_stripe_upe_params', array( $this, 'flag_subscription_cart' ) );
		add_filter( 'wc_stripe_params', array( $this, 'flag_subscription_cart' ) );
		add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'exclude_on_payment_request' ), 20, 2 );
	}

	/**
	 * One running subscription per product and customer. The add to cart check only knows
	 * logged in customers, so the account behind the billing email is checked here as well.
	 * Without it, a customer could register again with the same email and take the discount twice.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors, an added error stops the order.
	 */
	public function block_second_subscription( $data, $errors ): void {
		if ( ! self::cart_has_subscription() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id < 1 && ! empty( $data['billing_email'] ) ) {
			$user_id = (int) email_exists( sanitize_email( $data['billing_email'] ) );
		}

		if ( $user_id < 1 ) {
			return;
		}

		$repository = new \TasgAbo\Subscriptions\Repository();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['tasg_abo_plan'] ) && $repository->has_open( $user_id, (int) $item['product_id'] ) ) {
				$errors->add( 'tasg_abo_duplicate', __( 'You already have a subscription for this product. You can manage it under My Account.', 'tasg-abo' ) );
				return;
			}
		}
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

	/**
	 * Tells the Stripe checkout script that this cart holds a subscription. The script then
	 * asks Stripe to save the payment method for off session use ("setup future usage"), which
	 * makes Stripe's payment form offer only methods that can be charged again, and it shows
	 * the terms for future charges. The Stripe plugin sets this flag only for WooCommerce
	 * Subscriptions, so for our subscriptions it has to be set here.
	 *
	 * @param array $params Parameters handed to the Stripe checkout script.
	 */
	public function flag_subscription_cart( $params ) {
		if ( is_array( $params ) && self::cart_has_subscription() ) {
			$params['cartContainsSubscription']          = true;
			$params['subscriptionRequiresManualRenewal'] = false;

			// Methods the owner did not allow for subscriptions stay out of Stripe's payment form.
			$existing                             = isset( $params['excludedPaymentMethodTypes'] ) && is_array( $params['excludedPaymentMethodTypes'] ) ? $params['excludedPaymentMethodTypes'] : array();
			$params['excludedPaymentMethodTypes'] = array_values( array_unique( array_merge( $existing, StripeMethods::excluded_types() ) ) );
		}

		return $params;
	}

	/**
	 * The same exclusion on the server, so Stripe also refuses a method the form did not show.
	 *
	 * @param array          $request Payment request about to be sent to Stripe.
	 * @param \WC_Order|null $order   Order being paid.
	 */
	public function exclude_on_payment_request( $request, $order = null ) {
		if ( ! is_array( $request ) || ! $order instanceof \WC_Order || ! isset( $request['automatic_payment_methods'] ) ) {
			return $request;
		}

		$excluded = StripeMethods::excluded_types();

		if ( $excluded && self::order_has_subscription( $order ) ) {
			$existing                                 = isset( $request['excluded_payment_method_types'] ) ? (array) $request['excluded_payment_method_types'] : array();
			$request['excluded_payment_method_types'] = array_values( array_unique( array_merge( $existing, $excluded ) ) );
		}

		return $request;
	}

	/**
	 * A plan with free shipping: the shipping methods stay (so label and invoice
	 * automation see the usual method), only their price becomes 0 and the name says why.
	 *
	 * @param \WC_Shipping_Rate[] $rates   Rates WooCommerce found for the package.
	 * @param array               $package Cart package.
	 */
	public function free_shipping_rates( $rates, $package ) {
		$free = false;

		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			if ( ! empty( $item['tasg_abo_plan']['free_shipping'] ) ) {
				$free = true;
				break;
			}
		}

		if ( ! $free || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && (float) $rate->get_cost() > 0 ) {
				$rate->set_cost( 0 );
				$rate->set_taxes( array() );
				/* translators: %s: name of the shipping method */
				$rate->set_label( sprintf( __( '%s (free with your subscription)', 'tasg-abo' ), $rate->get_label() ) );
			}
		}

		return $rates;
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

	public function force_save_card( $force, $order_id = 0 ) {
		if ( self::cart_has_subscription() ) {
			return true;
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;

		return $order && self::order_has_subscription( $order ) ? true : $force;
	}

	public static function order_has_subscription( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( '' !== (string) $item->get_meta( '_tasg_abo_plan' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first payment must save the payment method on the Stripe customer, or a renewal
	 * is refused ("PaymentMethod was previously used without Customer attachment"). The
	 * Stripe plugin asks for that only for WooCommerce Subscriptions orders, so it is added
	 * here for ours, the same way the plugin does it for its own subscriptions. The Stripe
	 * mode of the first payment is noted on the order, so renewals use the same keys.
	 *
	 * @param array          $request Payment intent request about to be sent to Stripe.
	 * @param \WC_Order|null $order   Order being paid.
	 */
	public function save_for_renewals( $request, $order = null ) {
		if ( ! is_array( $request ) || ! $order instanceof \WC_Order || ! self::order_has_subscription( $order ) ) {
			return $request;
		}

		if ( class_exists( 'WC_Stripe_Mode' ) ) {
			$order->update_meta_data( StripeCharger::MODE_META, \WC_Stripe_Mode::is_test() ? 'test' : 'live' );
			$order->save_meta_data();
		}

		if ( ! empty( $request['setup_future_usage'] ) ) {
			return $request;
		}

		// Classic payment method flow: one setting for the whole intent.
		if ( empty( $request['confirmation_token'] ) ) {
			$request['setup_future_usage'] = 'off_session';
			return $request;
		}

		// Optimized Checkout (confirmation token): the setting belongs to the chosen method.
		$type = $this->confirmation_type( (string) $request['confirmation_token'], $order );

		if ( '' !== $type && empty( $request['payment_method_options'][ $type ]['setup_future_usage'] ) ) {
			$request['payment_method_options'][ $type ]['setup_future_usage'] = 'off_session';
		}

		return $request;
	}

	/**
	 * Payment method type of a confirmation token (card, sepa_debit and so on), empty if unknown.
	 */
	private function confirmation_type( string $token, \WC_Order $order ): string {
		if ( ! class_exists( 'WC_Stripe_API' ) ) {
			return '';
		}

		$data = \WC_Stripe_API::retrieve( 'confirmation_tokens/' . rawurlencode( $token ) );
		$type = is_object( $data ) && isset( $data->payment_method_preview->type ) ? (string) $data->payment_method_preview->type : '';

		// For the log: did the checkout form itself ask to save the method?
		$asked = is_object( $data ) && ! empty( $data->setup_future_usage ) ? (string) $data->setup_future_usage : 'none';
		Log::info( sprintf( 'Order #%d: Stripe method "%s", saved for renewals (form asked: %s).', $order->get_id(), '' !== $type ? $type : 'unknown', $asked ) );

		return $type;
	}

	/**
	 * Only gateways that can be charged again later are offered.
	 * PayPal is added when it is switched on in the settings.
	 */
	public function limit_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::cart_has_subscription() ) {
			return $gateways;
		}

		$prefixes = Settings::stripe_enabled() ? array( 'stripe' ) : array();

		if ( Settings::paypal_enabled() ) {
			$prefixes = array_merge( $prefixes, PayPalCharger::GATEWAY_IDS );
		}

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
