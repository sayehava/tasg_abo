<?php
/**
 * Subscription configuration and price maths for a product.
 *
 * @package TasgAbo
 */

namespace TasgAbo;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	public static function is_enabled( \WC_Product $product ): bool {
		return 'yes' === $product->get_meta( '_tasg_abo_enabled' ) && ! empty( self::intervals( $product ) );
	}

	/**
	 * Allowed intervals in weeks.
	 *
	 * @return int[]
	 */
	public static function intervals( \WC_Product $product ): array {
		$raw = (string) $product->get_meta( '_tasg_abo_intervals' );

		return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
	}

	/**
	 * Price a subscriber pays, never below zero.
	 */
	public static function subscriber_price( \WC_Product $product ): float {
		$price = (float) $product->get_price();
		$value = (float) $product->get_meta( '_tasg_abo_discount_value' );

		if ( $value <= 0 ) {
			return $price;
		}

		$discount = 'fixed' === $product->get_meta( '_tasg_abo_discount_type' )
			? $value
			: $price * min( 100, $value ) / 100;

		return max( 0.0, round( $price - $discount, wc_get_price_decimals() ) );
	}
}
