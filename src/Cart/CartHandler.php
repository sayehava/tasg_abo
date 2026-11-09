<?php
/**
 * Carries the subscription choice through cart, checkout and order item.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cart;

use TasgAbo\Pricing;

defined( 'ABSPATH' ) || exit;

final class CartHandler {

	public function register(): void {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discount' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'show_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 3 );
	}

	/**
	 * Store the chosen interval when the subscribe box is ticked.
	 * Different cart data makes WooCommerce keep a separate cart line.
	 */
	public function add_item_data( array $data, int $product_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['tasg_abo_subscribe'] ) ) {
			return $data;
		}

		$product = wc_get_product( $product_id );
		$weeks   = isset( $_POST['tasg_abo_interval'] ) ? absint( $_POST['tasg_abo_interval'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $product && Pricing::is_enabled( $product ) && in_array( $weeks, Pricing::intervals( $product ), true ) ) {
			$data['tasg_abo_interval'] = $weeks;
		}

		return $data;
	}

	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['tasg_abo_interval'] ) ) {
				continue;
			}

			$fresh = wc_get_product( $item['product_id'] );
			if ( $fresh ) {
				$item['data']->set_price( Pricing::subscriber_price( $fresh ) );
			}
		}
	}

	public function show_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['tasg_abo_interval'] ) ) {
			$weeks = (int) $cart_item['tasg_abo_interval'];

			$item_data[] = array(
				'key'   => __( 'Subscription', 'tasg-abo' ),
				/* translators: %d: number of weeks */
				'value' => sprintf( _n( 'every %d week', 'every %d weeks', $weeks, 'tasg-abo' ), $weeks ),
			);
		}

		return $item_data;
	}

	/**
	 * Hidden meta, read later when the subscription is created from the paid order.
	 */
	public function save_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values ): void {
		if ( ! empty( $values['tasg_abo_interval'] ) ) {
			$item->add_meta_data( '_tasg_abo_interval', (int) $values['tasg_abo_interval'], true );
		}
	}
}
