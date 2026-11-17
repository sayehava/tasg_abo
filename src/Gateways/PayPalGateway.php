<?php
/**
 * PayPal at checkout for subscription carts.
 *
 * The customer is sent to PayPal, pays the first order and approves later
 * charges in the same step (PayPal Vault, saved during purchase). Only an
 * opaque vault id is kept, as order meta, nothing else about the payment method.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Checkout\CheckoutRules;
use TasgAbo\Log;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

class PayPalGateway extends \WC_Payment_Gateway {

	public const ID = 'tasg_abo_paypal';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayPal (subscriptions)', 'tasg-abo' );
		$this->method_description = __( 'PayPal for subscription orders. Set up under WooCommerce > Settings > Subscriptions.', 'tasg-abo' );
		$this->title              = __( 'PayPal', 'tasg-abo' );
		$this->description        = __( 'You pay with PayPal now and allow the next payments of your subscription. You can cancel at any time.', 'tasg-abo' );
		$this->has_fields         = false;
		$this->enabled            = 'yes';
	}

	/**
	 * Offered only for carts with a subscription, and only when set up.
	 */
	public function is_available() {
		return Settings::paypal_enabled() && CheckoutRules::cart_has_subscription() && Settings::paypal_configured( self::cart_in_sandbox() );
	}

	/**
	 * A test subscription (test phase on, or a test plan) pays in the sandbox when that is switched on.
	 */
	private static function cart_in_sandbox(): bool {
		if ( ! Settings::paypal_sandbox() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['tasg_abo_plan'] ) && ( Settings::admin_only() || ! empty( $item['tasg_abo_plan']['test'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function order_in_sandbox( \WC_Order $order ): bool {
		if ( ! Settings::paypal_sandbox() ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			if ( 'yes' === (string) $item->get_meta( '_tasg_abo_test' ) ) {
				return true;
			}
		}

		return false;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$return = add_query_arg(
			array(
				'tasg_abo_pp' => 'return',
				'order'       => $order->get_id(),
				'key'         => $order->get_order_key(),
			),
			home_url( '/' )
		);

		// Noted before the call: the return page and every renewal use the same PayPal mode.
		$sandbox = self::order_in_sandbox( $order );
		$order->update_meta_data( PayPalApi::SANDBOX_META, $sandbox ? 'yes' : 'no' );

		if ( $sandbox ) {
			$order->add_order_note( __( 'Paid in the PayPal sandbox (test order). No real money was moved.', 'tasg-abo' ) );
		}

		$result = ( new PayPalApi( $sandbox ) )->request(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'invoice_id' => (string) $order->get_order_number(),
						'custom_id'  => (string) $order->get_id(),
						'amount'     => array(
							'currency_code' => $order->get_currency(),
							'value'         => wc_format_decimal( $order->get_total(), 2 ),
						),
					),
				),
				'payment_source' => array(
					'paypal' => array(
						'attributes'         => array(
							'vault' => array(
								'store_in_vault' => 'ON_SUCCESS',
								'usage_type'     => 'MERCHANT',
							),
						),
						'experience_context' => array(
							'user_action'         => 'PAY_NOW',
							'shipping_preference' => 'NO_SHIPPING',
							'return_url'          => $return,
							'cancel_url'          => wc_get_checkout_url(),
						),
					),
				),
			),
			'tasg-abo-start-' . $order->get_id() . '-' . substr( md5( $order->get_order_key() . microtime() ), 0, 8 )
		);

		$link = '';
		foreach ( (array) ( $result['data']['links'] ?? array() ) as $item ) {
			if ( in_array( $item['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
				$link = (string) $item['href'];
				break;
			}
		}

		if ( '' === $link ) {
			Log::warning( sprintf( 'Order #%d: PayPal order could not be created: %s', $order->get_id(), $result['error'] ) );
			wc_add_notice( __( 'PayPal is not available right now. Please try again or choose another payment method.', 'tasg-abo' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_tasg_abo_pp_order', (string) ( $result['data']['id'] ?? '' ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $link,
		);
	}
}
