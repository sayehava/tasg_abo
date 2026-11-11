<?php
/**
 * Charges a vaulted PayPal payment method without the customer present.
 *
 * EXPERIMENTAL. It reuses the renewal handler of the WooCommerce PayPal
 * Payments plugin (it knows the vault tokens and the connection to PayPal),
 * which is why no PayPal keys are needed here and nothing is stored by this
 * plugin. It only works when vaulting ("save PayPal for future payments") is
 * enabled in the PayPal plugin and the customer saved the payment method at
 * checkout. Not tested against a live PayPal account yet.
 *
 * Same result array as StripeCharger.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class PayPalCharger {

	public const GATEWAY_IDS = array( 'ppcp-gateway', 'ppcp-credit-card-gateway' );

	public function supports( string $gateway_id ): bool {
		return Settings::paypal_enabled()
			&& in_array( $gateway_id, self::GATEWAY_IDS, true )
			&& class_exists( '\WooCommerce\PayPalCommerce\PPCP' );
	}

	public function charge( \WC_Order $order, object $sub, string $idempotency ): array {
		require_once __DIR__ . '/paypal-shim.php';

		try {
			$handler = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'wc-subscriptions.renewal-handler' );
			$order->save();

			// Does not throw: on any problem it marks the order as failed and logs it.
			$handler->renew( $order );
		} catch ( \Throwable $e ) {
			return $this->result( 'failed', '', $e->getMessage() );
		}

		$fresh = wc_get_order( $order->get_id() );

		if ( ! $fresh || $fresh->has_status( 'failed' ) ) {
			return $this->result( 'failed', '', 'PayPal did not accept the payment.' );
		}

		$transaction = (string) $fresh->get_transaction_id();

		if ( '' === $transaction ) {
			return $this->result( 'failed', '', 'PayPal returned no transaction.' );
		}

		return $this->result( $fresh->has_status( array( 'processing', 'completed' ) ) ? 'succeeded' : 'processing', $transaction, '' );
	}

	private function result( string $status, string $transaction, string $message ): array {
		return array(
			'status'  => $status,
			'id'      => $transaction,
			'charge'  => $transaction,
			'message' => $message,
		);
	}
}
