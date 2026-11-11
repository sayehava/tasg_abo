<?php
/**
 * Carries the subscription choice through cart, checkout and order item.
 * The chosen plan travels as a small copy (id, name, period, reminder time).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cart;

use TasgAbo\Pricing;

defined( 'ABSPATH' ) || exit;

final class CartHandler {

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_plan' ), 5, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discount' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'show_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'mark_test_order' ), 10, 1 );
	}

	/**
	 * The plan the customer picked, if the box is ticked and the plan is valid for the product.
	 */
	private function posted_plan( int $product_id ): ?\TasgAbo\Plans\Plan {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['tasg_abo_subscribe'] ) ) {
			return null;
		}

		$plan_id = isset( $_POST['tasg_abo_plan'] ) ? absint( $_POST['tasg_abo_plan'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $product_id );

		if ( ! $product || ! Pricing::is_enabled( $product ) ) {
			return null;
		}

		$plans = Pricing::plans( $product );

		return $plans[ $plan_id ] ?? null;
	}

	/**
	 * Ticking "subscribe" without a valid plan stops the add to cart with a message.
	 */
	public function validate_plan( $passed, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ticked = ! empty( $_POST['tasg_abo_subscribe'] ) && \TasgAbo\Settings::visible_to_current_user();

		if ( $passed && $ticked && ! $this->posted_plan( (int) $product_id ) ) {
			wc_add_notice( __( 'Please choose a subscription plan.', 'tasg-abo' ), 'error' );
			return false;
		}

		return $passed;
	}

	public function add_item_data( array $data, int $product_id ): array {
		$plan = $this->posted_plan( $product_id );

		if ( $plan ) {
			$data['tasg_abo_plan'] = $plan->snapshot();
		}

		return $data;
	}

	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['tasg_abo_plan'] ) ) {
				continue;
			}

			$fresh = wc_get_product( $item['product_id'] );
			if ( $fresh ) {
				$item['data']->set_price( Pricing::subscriber_price( $fresh, (int) $item['tasg_abo_plan']['id'] ) );
			}
		}
	}

	public function show_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['tasg_abo_plan'] ) ) {
			$plan = $cart_item['tasg_abo_plan'];

			// Short on purpose: the side cart is narrow. The plan name already says the period.
			$item_data[] = array(
				'key'   => __( 'Subscription', 'tasg-abo' ),
				'value' => '' !== (string) $plan['name'] ? (string) $plan['name'] : \TasgAbo\Plans\Period::label( (int) $plan['value'], (string) $plan['unit'] ),
			);
		}

		return $item_data;
	}

	/**
	 * A subscription is a test while the test phase is on (only managers can subscribe
	 * then) or when its plan is a test plan.
	 *
	 * @param array $item Cart item.
	 */
	private function is_test_item( array $item ): bool {
		return ! empty( $item['tasg_abo_plan'] ) && ( \TasgAbo\Settings::admin_only() || ! empty( $item['tasg_abo_plan']['test'] ) );
	}

	/**
	 * Marks the first order of a test subscription as a test order.
	 *
	 * @param \WC_Order $order Order being created at checkout.
	 */
	public function mark_test_order( $order ): void {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $this->is_test_item( $item ) ) {
				\TasgAbo\TestMarker::apply( $order );
				return;
			}
		}
	}

	/**
	 * Hidden meta with the plan copy, read when the subscription is created from the paid order.
	 */
	public function save_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values ): void {
		if ( ! empty( $values['tasg_abo_plan'] ) ) {
			$item->add_meta_data( '_tasg_abo_plan', wp_json_encode( $values['tasg_abo_plan'] ), true );

			// Language of the customer right now (WPML code), so later emails use it even
			// when the order is processed in a webhook or background job.
			if ( $this->is_test_item( $values ) ) {
				$item->add_meta_data( '_tasg_abo_test', 'yes', true );
			}

			$lang = apply_filters( 'wpml_current_language', null );
			$item->add_meta_data( '_tasg_abo_lang', is_string( $lang ) && '' !== $lang ? $lang : determine_locale(), true );
		}
	}
}
