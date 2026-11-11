<?php
/**
 * A subscription must be ordered on its own: one cart line, nothing else.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cart;

use TasgAbo\Checkout\CheckoutRules;

defined( 'ABSPATH' ) || exit;

final class SingleItemRule {

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart' ) );
	}

	/**
	 * @param bool $passed     Result of earlier checks.
	 * @param int  $product_id Product being added.
	 */
	public function validate_add( $passed, $product_id ) {
		if ( ! $passed || ! WC()->cart || WC()->cart->is_empty() ) {
			return $passed;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$adding_subscription = ! empty( $_POST['tasg_abo_subscribe'] );

		if ( $adding_subscription || CheckoutRules::cart_has_subscription() ) {
			wc_add_notice(
				__( 'A subscription must be ordered on its own. Please finish or empty your cart first.', 'tasg-abo' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Safety net at cart and checkout, for carts built in any other way.
	 */
	public function validate_cart(): void {
		if ( CheckoutRules::cart_has_subscription() && count( WC()->cart->get_cart() ) > 1 ) {
			wc_add_notice(
				__( 'A subscription must be ordered on its own. Please remove the other products from your cart.', 'tasg-abo' ),
				'error'
			);
		}
	}
}
