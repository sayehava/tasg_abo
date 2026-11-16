<?php
/**
 * Small client for the PayPal REST API, using the shop's own app credentials
 * from the plugin settings. It does not depend on the WooCommerce PayPal
 * Payments plugin.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class PayPalApi {

	private const LIVE    = 'https://api-m.paypal.com';
	private const SANDBOX = 'https://api-m.sandbox.paypal.com';

	public function base(): string {
		return Settings::paypal_sandbox() ? self::SANDBOX : self::LIVE;
	}

	/**
	 * OAuth access token, kept until shortly before it expires.
	 * Returns an empty string when PayPal refuses the credentials.
	 */
	private function token(): string {
		$cache = 'tasg_abo_pp_token_' . md5( $this->base() . Settings::paypal_client_id() );
		$known = get_transient( $cache );

		if ( is_string( $known ) && '' !== $known ) {
			return $known;
		}

		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( Settings::paypal_client_id() . ':' . Settings::paypal_secret() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';

		if ( '' !== $token ) {
			set_transient( $cache, $token, max( 60, (int) ( $data['expires_in'] ?? 300 ) - 120 ) );
		}

		return $token;
	}

	/**
	 * One API call. Result: http (status code, 0 when PayPal was not reached),
	 * data (decoded JSON) and error (text, empty on success).
	 *
	 * @param string $request_id Same value for the same action, so PayPal never does it twice.
	 */
	public function request( string $method, string $path, array $body = array(), string $request_id = '' ): array {
		$token = $this->token();

		if ( '' === $token ) {
			return array(
				'http'  => 0,
				'data'  => array(),
				'error' => 'PayPal did not accept the API credentials.',
			);
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);

		if ( '' !== $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => $headers,
		);

		// PayPal wants a JSON body on POST, an empty object when there is nothing to send.
		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		} elseif ( 'POST' === $method ) {
			$args['body'] = '{}';
		}

		$response = wp_remote_request( $this->base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'http'  => 0,
				'data'  => array(),
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		return array(
			'http'  => $code,
			'data'  => $data,
			'error' => $code >= 400 ? $this->describe( $data, $code ) : '',
		);
	}

	private function describe( array $data, int $code ): string {
		$text = (string) ( $data['message'] ?? $data['error_description'] ?? 'PayPal error' );
		$more = isset( $data['details'][0]['issue'] ) ? ' (' . $data['details'][0]['issue'] . ')' : '';

		return $text . $more . ' [HTTP ' . $code . ']';
	}
}
