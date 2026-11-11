<?php
/**
 * Addresses for the next renewal of a subscription.
 *
 * The address follows the last order of the subscription: the first renewal
 * uses the address of the first order, the second renewal the one of the first
 * renewal, and so on. If the customer corrected the shipping address through
 * the link in the reminder email, that correction is used for the next renewal
 * and then becomes part of that order, so it carries on from there.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Address {

	/** Shipping fields the customer may correct. */
	public const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' );

	/**
	 * Last order of the subscription, or the first one as a fallback.
	 */
	public static function last_order( object $sub ): ?\WC_Order {
		$order = wc_get_order( (int) $sub->last_order_id );

		if ( ! $order ) {
			$order = wc_get_order( (int) $sub->origin_order_id );
		}

		return $order ? $order : null;
	}

	public static function billing( object $sub ): array {
		$order = self::last_order( $sub );

		return $order ? $order->get_address( 'billing' ) : array();
	}

	public static function shipping( object $sub ): array {
		$order   = self::last_order( $sub );
		$address = $order ? $order->get_address( 'shipping' ) : array();

		if ( empty( $address['address_1'] ) ) {
			$address = self::billing( $sub );
		}

		$override = ! empty( $sub->address_override ) ? json_decode( (string) $sub->address_override, true ) : null;

		return is_array( $override ) ? array_merge( $address, $override ) : $address;
	}

	/**
	 * Address as plain text lines, for emails.
	 */
	public static function formatted( array $address ): string {
		$text = str_replace( array( '<br/>', '<br>', '<br />' ), "\n", WC()->countries->get_formatted_address( $address ) );

		return wp_strip_all_tags( $text );
	}
}
