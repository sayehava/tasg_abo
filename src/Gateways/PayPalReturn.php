<?php
/**
 * Where the customer lands after approving at PayPal: captures the payment
 * and keeps the vault id (the permission for later charges) on the order.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Log;

defined( 'ABSPATH' ) || exit;

final class PayPalReturn {

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key proves the request.
		if ( 'return' !== ( $_GET['tasg_abo_pp'] ?? '' ) ) {
			return;
		}

		$order = wc_get_order( absint( $_GET['order'] ?? 0 ) );
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		if ( ! $order || ! is_string( $key ) || ! hash_equals( $order->get_order_key(), $key ) || PayPalGateway::ID !== $order->get_payment_method() ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$paypal_order = (string) $order->get_meta( '_tasg_abo_pp_order' );
		$result       = PayPalApi::for_order( $order )->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $paypal_order ) . '/capture', array(), 'tasg-abo-capture-' . $order->get_id() );
		$capture      = $result['data']['purchase_units'][0]['payments']['captures'][0] ?? array();
		$status       = (string) ( $capture['status'] ?? '' );

		if ( '' === $paypal_order || ! in_array( $status, array( 'COMPLETED', 'PENDING' ), true ) ) {
			Log::warning( sprintf( 'Order #%d: PayPal capture failed: %s', $order->get_id(), $result['error'] ) );
			$order->add_order_note( __( 'PayPal payment was not completed.', 'tasg-abo' ) );
			wc_add_notice( __( 'The PayPal payment was not completed. Please try again.', 'tasg-abo' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$vault = $result['data']['payment_source']['paypal']['attributes']['vault'] ?? array();

		if ( 'VAULTED' === ( $vault['status'] ?? '' ) && ! empty( $vault['id'] ) ) {
			$order->update_meta_data( '_tasg_abo_pp_vault_id', sanitize_text_field( $vault['id'] ) );
		} else {
			$order->add_order_note( __( 'PayPal did not save the payment method for later charges.', 'tasg-abo' ) );
		}

		// Saved first: the subscription is created the moment the order turns "processing".
		$order->save();

		if ( 'COMPLETED' === $status ) {
			$order->payment_complete( (string) ( $capture['id'] ?? '' ) );
		} else {
			$order->update_status( 'on-hold', __( 'PayPal payment is pending.', 'tasg-abo' ) );
		}

		$order->save();
		WC()->cart->empty_cart();
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}
}
