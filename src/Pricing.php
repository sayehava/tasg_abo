<?php
/**
 * Subscription configuration of a product and the subscriber price.
 *
 * Product meta:
 *   _tasg_abo_enabled         yes|no
 *   _tasg_abo_plans           array: plan id => discount override ('' = use the product discount)
 *   _tasg_abo_default_plan    plan id preselected for this product (optional)
 *   _tasg_abo_discount_type   percent|fixed
 *   _tasg_abo_discount_value  default discount of the product
 *
 * @package TasgAbo
 */

namespace TasgAbo;

use TasgAbo\Plans\Plan;
use TasgAbo\Plans\PlanRepository;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	/**
	 * Plans a product offers: plan id => discount override.
	 *
	 * @return array<int,string>
	 */
	public static function product_plans( \WC_Product $product ): array {
		$stored = $product->get_meta( '_tasg_abo_plans' );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Plans the current visitor can choose for this product.
	 *
	 * @return Plan[] keyed by plan id
	 */
	public static function plans( \WC_Product $product ): array {
		$offered = self::product_plans( $product );
		$plans   = array();

		foreach ( ( new PlanRepository() )->all() as $id => $plan ) {
			if ( isset( $offered[ $id ] ) && $plan->visible_to_current_user() ) {
				$plans[ $id ] = $plan;
			}
		}

		return $plans;
	}

	/**
	 * Plan preselected for customers: the product's own choice, else the global
	 * default plan, else the first plan.
	 *
	 * @param Plan[] $plans Plans the visitor can choose, keyed by id.
	 */
	public static function default_plan_id( array $plans, \WC_Product $product ): int {
		$chosen = (int) $product->get_meta( '_tasg_abo_default_plan' );

		if ( isset( $plans[ $chosen ] ) ) {
			return $chosen;
		}

		foreach ( $plans as $id => $plan ) {
			if ( $plan->default ) {
				return (int) $id;
			}
		}

		return $plans ? (int) array_key_first( $plans ) : 0;
	}

	public static function is_enabled( \WC_Product $product ): bool {
		return Settings::visible_to_current_user()
			&& 'yes' === $product->get_meta( '_tasg_abo_enabled' )
			&& ! empty( self::plans( $product ) );
	}

	/**
	 * Price a subscriber pays for a plan, never below zero.
	 * The plan's own discount wins over the product's default discount.
	 */
	public static function subscriber_price( \WC_Product $product, int $plan_id = 0 ): float {
		$price    = (float) $product->get_price();
		$offered  = self::product_plans( $product );
		$override = $plan_id && isset( $offered[ $plan_id ] ) ? (string) $offered[ $plan_id ] : '';
		$value    = '' !== $override ? (float) $override : (float) $product->get_meta( '_tasg_abo_discount_value' );

		if ( $value <= 0 ) {
			return $price;
		}

		$discount = 'fixed' === $product->get_meta( '_tasg_abo_discount_type' )
			? $value
			: $price * min( 100, $value ) / 100;

		return max( 0.0, round( $price - $discount, wc_get_price_decimals() ) );
	}
}
