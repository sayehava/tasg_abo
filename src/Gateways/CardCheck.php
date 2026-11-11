<?php
/**
 * Finds out when the saved Stripe card of a subscription expires.
 *
 * Only the expiry month and year that WooCommerce keeps with the saved payment
 * method are read. Cards without an expiry (SEPA, PayPal) give no result.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

defined( 'ABSPATH' ) || exit;

final class CardCheck {

	/**
	 * @return array{name:string,expires:int,label:string}|null Name for the customer, expiry timestamp (end of the last valid day), "MM/YYYY".
	 */
	public static function info( object $sub ): ?array {
		if ( 0 !== strpos( (string) $sub->gateway, 'stripe' ) ) {
			return null;
		}

		$method = self::method_id( $sub );

		if ( '' === $method ) {
			return null;
		}

		foreach ( \WC_Payment_Tokens::get_customer_tokens( (int) $sub->user_id ) as $token ) {
			if ( (string) $token->get_token() !== $method || ! $token instanceof \WC_Payment_Token_CC ) {
				continue;
			}

			$year  = (int) $token->get_expiry_year();
			$month = (int) $token->get_expiry_month();

			if ( $year < 2000 || $month < 1 || $month > 12 ) {
				return null;
			}

			// A card is valid until the end of its expiry month.
			$end = ( new \DateTimeImmutable( sprintf( '%04d-%02d-01 23:59:59', $year, $month ), wp_timezone() ) )->modify( 'last day of this month' );

			return array(
				'name'    => (string) $token->get_display_name(),
				'expires' => $end->getTimestamp(),
				'label'   => sprintf( '%02d/%04d', $month, $year ),
			);
		}

		return null;
	}

	/**
	 * Id of the payment method that would be charged: the one the customer picked,
	 * else the one stored with the last order, else with the first order.
	 */
	private static function method_id( object $sub ): string {
		if ( (int) $sub->payment_token_id > 0 ) {
			$token = \WC_Payment_Tokens::get( (int) $sub->payment_token_id );

			if ( $token && (int) $token->get_user_id() === (int) $sub->user_id ) {
				return (string) $token->get_token();
			}
		}

		foreach ( array( (int) $sub->last_order_id, (int) $sub->origin_order_id ) as $order_id ) {
			$order = $order_id ? wc_get_order( $order_id ) : false;
			$id    = $order ? (string) $order->get_meta( '_stripe_source_id' ) : '';

			if ( '' !== $id ) {
				return $id;
			}
		}

		return '';
	}
}
