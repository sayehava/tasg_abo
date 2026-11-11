<?php
/**
 * A subscription must be ordered on its own: one cart line, nothing else.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cart;

use TasgAbo\Checkout\CheckoutRules;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class SingleItemRule {

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_quantity' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart' ) );
	}

	/**
	 * @param bool $passed     Result of earlier checks.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity being added.
	 */
	public function validate_add( $passed, $product_id, $quantity = 1 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$adding_subscription = ! empty( $_POST['tasg_abo_subscribe'] ) && Settings::visible_to_current_user();

		if ( $passed && $adding_subscription && (int) $quantity !== 1 ) {
			wc_add_notice( __( 'A subscription is always for one piece.', 'tasg-abo' ), 'error' );
			return false;
		}

		if ( ! $passed || ! WC()->cart || WC()->cart->is_empty() ) {
			return $passed;
		}

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
	 * A subscription line shows a fixed quantity of 1 in the cart.
	 */
	public function lock_quantity( $html, $cart_item_key, $cart_item ) {
		if ( empty( $cart_item['tasg_abo_plan'] ) ) {
			return $html;
		}

		return '1<input type="hidden" name="cart[' . esc_attr( (string) $cart_item_key ) . '][qty]" value="1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Safety net at cart and checkout, for carts built in any other way.
	 */
	public function validate_cart(): void {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['tasg_abo_plan'] ) && 1 !== (int) $item['quantity'] ) {
				WC()->cart->set_quantity( $key, 1, true );
			}
		}

		if ( CheckoutRules::cart_has_subscription() && count( WC()->cart->get_cart() ) > 1 ) {
			wc_add_notice(
				__( 'A subscription must be ordered on its own. Please remove the other products from your cart.', 'tasg-abo' ),
				'error'
			);
		}
	}
}
