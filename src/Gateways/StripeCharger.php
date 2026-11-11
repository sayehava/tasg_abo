<?php
/**
 * Charges a saved Stripe payment method without the customer present.
 *
 * It talks to the Stripe API directly with the secret key already stored by
 * the WooCommerce Stripe Gateway plugin, because that plugin's own renewal
 * code only runs together with WooCommerce Subscriptions.
 *
 * Result array: status (succeeded|processing|failed|error), id, charge, message.
 * "error" means a temporary problem (network, Stripe outage): nothing was
 * charged and it is safe to try again later. "failed" is final (declined card).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

defined( 'ABSPATH' ) || exit;

final class StripeCharger {

	private const API = 'https://api.stripe.com/v1/';

	private const ZERO_DECIMAL = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );

	public function supports( string $gateway_id ): bool {
		return 0 === strpos( $gateway_id, 'stripe' );
	}

	private function secret_key(): string {
		$settings = (array) get_option( 'woocommerce_stripe_settings', array() );
		$test     = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
		$key      = $test ? ( $settings['test_secret_key'] ?? '' ) : ( $settings['secret_key'] ?? '' );

		return (string) $key;
	}

	private function minor_units( float $amount, string $currency ): int {
		$factor = in_array( strtolower( $currency ), self::ZERO_DECIMAL, true ) ? 1 : 100;

		return (int) round( $amount * $factor );
	}

	/**
	 * @param string $idempotency Same value for the same renewal, so a repeated
	 *                            request can never charge twice.
	 */
	public function charge( \WC_Order $order, object $sub, string $idempotency ): array {
		$key = $this->secret_key();

		// No card data is stored by this plugin. The Stripe references come from
		// the last order of the subscription, where the Stripe plugin keeps them.
		$last     = wc_get_order( (int) $sub->last_order_id );
		$customer = $last ? (string) $last->get_meta( '_stripe_customer_id' ) : '';
		$method   = $last ? (string) $last->get_meta( '_stripe_source_id' ) : '';

		if ( '' === $key || '' === $customer || '' === $method ) {
			return $this->result( 'failed', '', '', 'Missing Stripe key, customer or payment method on the last order.' );
		}

		$response = $this->request(
			'payment_intents',
			array(
				'amount'                                     => $this->minor_units( (float) $order->get_total(), $order->get_currency() ),
				'currency'                                   => strtolower( $order->get_currency() ),
				'customer'                                   => $customer,
				'payment_method'                             => $method,
				'off_session'                                => 'true',
				'confirm'                                    => 'true',
				'automatic_payment_methods[enabled]'         => 'true',
				'automatic_payment_methods[allow_redirects]' => 'never',
				'description'                                => 'Subscription #' . (int) $sub->id,
				'metadata[subscription_id]'                  => (int) $sub->id,
			),
			$key,
			$idempotency
		);

		if ( null === $response ) {
			return $this->result( 'error', '', '', 'Stripe could not be reached.' );
		}

		if ( isset( $response['error'] ) ) {
			$intent  = $response['error']['payment_intent']['id'] ?? '';
			$message = (string) ( $response['error']['message'] ?? 'Payment failed.' );

			if ( '' !== $intent ) {
				$this->request( 'payment_intents/' . rawurlencode( $intent ) . '/cancel', array(), $key, '' );
			}

			// Stripe side outages are temporary, everything else is a final decline.
			$type = (string) ( $response['error']['type'] ?? '' );

			return $this->result( in_array( $type, array( 'api_error', 'rate_limit_error' ), true ) ? 'error' : 'failed', $intent, '', $message );
		}

		$status = (string) ( $response['status'] ?? '' );
		$id     = (string) ( $response['id'] ?? '' );
		$charge = (string) ( $response['latest_charge'] ?? '' );

		if ( 'succeeded' === $status || 'processing' === $status ) {
			return $this->result( $status, $id, $charge, '' );
		}

		$this->request( 'payment_intents/' . rawurlencode( $id ) . '/cancel', array(), $key, '' );

		return $this->result( 'failed', $id, '', 'Payment needs customer action: ' . $status );
	}

	private function result( string $status, string $id, string $charge, string $message ): array {
		return array(
			'status'  => $status,
			'id'      => $id,
			'charge'  => $charge,
			'message' => $message,
		);
	}

	/**
	 * @return array|null Decoded JSON, or null when the request itself failed.
	 */
	private function request( string $path, array $body, string $key, string $idempotency ): ?array {
		$headers = array( 'Authorization' => 'Bearer ' . $key );

		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}

		$reply = wp_remote_post(
			self::API . $path,
			array(
				'timeout' => 45,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $reply ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $reply ), true );

		return is_array( $data ) ? $data : null;
	}
}
