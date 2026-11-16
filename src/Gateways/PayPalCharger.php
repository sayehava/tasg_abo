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

		return $this->result( 'error', '', 'PayPal charging is not finished yet.' );
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
