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
}
