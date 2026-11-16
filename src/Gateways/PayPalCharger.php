<?php
/**
 * Charges a saved PayPal payment method (vault id) without the customer present.
 *
 * Talks to PayPal directly through PayPalApi with the shop's own app
 * credentials. The vault id lives on the last order of the subscription and is
 * handed on to every renewal order, so the chain continues.
 *
 * Same result array as StripeCharger: status (succeeded|processing|failed|error),
 * id, charge, message. "error" means a temporary problem, nothing was charged.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class PayPalCharger {

	public const GATEWAY_IDS = array( PayPalGateway::ID );

	public const VAULT_META = '_tasg_abo_pp_vault_id';

	public function supports( string $gateway_id ): bool {
		return Settings::paypal_enabled() && Settings::paypal_configured() && in_array( $gateway_id, self::GATEWAY_IDS, true );
	}

	public function charge( \WC_Order $order, object $sub, string $idempotency ): array {
		$vault = '';

		foreach ( array( (int) $sub->last_order_id, (int) $sub->origin_order_id ) as $order_id ) {
			$source = $order_id ? wc_get_order( $order_id ) : false;
			$vault  = $source ? (string) $source->get_meta( self::VAULT_META ) : '';

			if ( '' !== $vault ) {
				break;
			}
		}

		if ( '' === $vault ) {
			return $this->result( 'failed', '', 'No saved PayPal payment method on the last order.' );
		}

		$api    = new PayPalApi();
		$result = $api->request(
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
				'payment_source' => array( 'paypal' => array( 'vault_id' => $vault ) ),
			),
			$idempotency
		);

		// A temporary problem: PayPal not reached or a server error. Nothing was charged.
		if ( 0 === $result['http'] || $result['http'] >= 500 ) {
			return $this->result( 'error', '', $result['error'] );
		}

		// Not accepted (declined, vault removed by the customer and so on). Final.
		if ( '' !== $result['error'] ) {
			return $this->result( 'failed', '', $result['error'] );
		}

		// Normally COMPLETED at once. If PayPal waits for a capture call, do it.
		if ( 'COMPLETED' !== ( $result['data']['status'] ?? '' ) && ! empty( $result['data']['id'] ) ) {
			$result = $api->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $result['data']['id'] ) . '/capture', array(), $idempotency . '-capture' );

			if ( '' !== $result['error'] ) {
				return $this->result( $result['http'] >= 500 || 0 === $result['http'] ? 'error' : 'failed', '', $result['error'] );
			}
		}

		$capture = $result['data']['purchase_units'][0]['payments']['captures'][0] ?? array();
		$id      = (string) ( $capture['id'] ?? '' );

		switch ( (string) ( $capture['status'] ?? '' ) ) {
			case 'COMPLETED':
				return $this->result( 'succeeded', $id, '' );
			case 'PENDING':
				return $this->result( 'processing', $id, '' );
			default:
				return $this->result( 'failed', $id, 'PayPal did not complete the payment.' );
		}
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
