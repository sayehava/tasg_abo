<?php
/**
 * Subscription fields on the product edit screen.
 *
 * Meta keys:
 *   _tasg_abo_enabled         yes|no
 *   _tasg_abo_plans           plan id => discount override ('' = product discount)
 *   _tasg_abo_default_plan    plan id preselected for this product, 0 = use the global default
 *   _tasg_abo_discount_type   percent|fixed
 *   _tasg_abo_discount_value  default discount, 0 or empty means none
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Plans\PlanRepository;

defined( 'ABSPATH' ) || exit;

final class ProductSettings {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );
	}

	public function render_fields(): void {
		global $post;

		$product = $post ? wc_get_product( $post->ID ) : null;
		$offered = $product ? $product->get_meta( '_tasg_abo_plans' ) : '';
		$is_new  = ! is_array( $offered );
		$chosen  = $product ? (int) $product->get_meta( '_tasg_abo_default_plan' ) : 0;
		$offered = is_array( $offered ) ? $offered : array();

		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_tasg_abo_enabled',
				'label'       => __( 'Subscription', 'tasg-abo' ),
				'description' => __( 'Let customers subscribe to this product.', 'tasg-abo' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_tasg_abo_discount_type',
				'label'   => __( 'Subscriber discount type', 'tasg-abo' ),
				'options' => array(
					'percent' => __( 'Percent', 'tasg-abo' ),
					'fixed'   => __( 'Fixed amount', 'tasg-abo' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_tasg_abo_discount_value',
				'label'       => __( 'Subscriber discount', 'tasg-abo' ),
				'description' => __( 'Taken off the price of every subscription order. Leave empty for no discount. A plan below can have its own discount.', 'tasg-abo' ),
				'desc_tip'    => true,
				'data_type'   => 'price',
			)
		);

		echo '<p class="form-field"><label>' . esc_html__( 'Plans offered', 'tasg-abo' ) . '</label><span class="description" style="margin-left:0">';

		$plans = ( new PlanRepository() )->all();

		if ( ! $plans ) {
			echo esc_html__( 'No plans yet. Create them under WooCommerce > Subscription plans.', 'tasg-abo' );
		}

		echo '</span></p>';

		foreach ( $plans as $id => $plan ) {
			// A product without a saved choice starts with the default plan ticked.
			$checked  = isset( $offered[ $id ] ) || ( $is_new && $plan->default );
			$override = isset( $offered[ $id ] ) ? (string) $offered[ $id ] : '';
			$suffix   = $plan->test ? ' ' . __( '(test plan)', 'tasg-abo' ) : '';

			echo '<p class="form-field"><label>' . esc_html( $plan->title() . $suffix ) . '</label>';
			echo '<input type="checkbox" class="checkbox" name="_tasg_abo_plan[' . esc_attr( (string) $id ) . ']" value="1"' . checked( $checked, true, false ) . '> ';
			echo '<span class="description" style="margin-left:0">' . esc_html( $plan->period_label() ) . '</span> ';
			echo '<input type="text" class="short wc_input_price" style="width:90px" name="_tasg_abo_plan_discount[' . esc_attr( (string) $id ) . ']" value="' . esc_attr( $override ) . '" placeholder="' . esc_attr__( 'Discount', 'tasg-abo' ) . '"> ';

			// The product's own default wins. Without one the global default plan is shown as selected.
			$is_default = $chosen ? $chosen === $id : ( $is_new && $plan->default );
			echo '<label><input type="radio" name="_tasg_abo_default_plan" value="' . esc_attr( (string) $id ) . '"' . checked( $is_default, true, false ) . '> ' . esc_html__( 'Default for this product', 'tasg-abo' ) . '</label>';
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param \WC_Product $product Product being saved.
	 */
	public function save_fields( $product ): void {
		// Nonce and capability are already verified by WooCommerce before this hook fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$enabled = isset( $_POST['_tasg_abo_enabled'] ) ? 'yes' : 'no';

		$type = isset( $_POST['_tasg_abo_discount_type'] ) ? sanitize_key( wp_unslash( $_POST['_tasg_abo_discount_type'] ) ) : 'percent';
		$type = in_array( $type, array( 'percent', 'fixed' ), true ) ? $type : 'percent';

		$value = isset( $_POST['_tasg_abo_discount_value'] ) ? wc_format_decimal( wp_unslash( $_POST['_tasg_abo_discount_value'] ) ) : '';

		$ticked    = isset( $_POST['_tasg_abo_plan'] ) && is_array( $_POST['_tasg_abo_plan'] ) ? array_keys( $_POST['_tasg_abo_plan'] ) : array();
		$overrides = isset( $_POST['_tasg_abo_plan_discount'] ) && is_array( $_POST['_tasg_abo_plan_discount'] ) ? wp_unslash( $_POST['_tasg_abo_plan_discount'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$known = ( new PlanRepository() )->all();
		$plans = array();

		foreach ( $ticked as $id ) {
			$id = (int) $id;

			if ( ! isset( $known[ $id ] ) ) {
				continue;
			}

			$plans[ $id ] = isset( $overrides[ $id ] ) ? $this->clean_discount( (string) $overrides[ $id ], $type ) : '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$default = isset( $_POST['_tasg_abo_default_plan'] ) ? absint( $_POST['_tasg_abo_default_plan'] ) : 0;
		$default = isset( $plans[ $default ] ) ? $default : 0;

		$product->update_meta_data( '_tasg_abo_enabled', $enabled );
		$product->update_meta_data( '_tasg_abo_default_plan', $default );
		$product->update_meta_data( '_tasg_abo_plans', $plans );
		$product->update_meta_data( '_tasg_abo_discount_type', $type );
		$product->update_meta_data( '_tasg_abo_discount_value', $this->clean_discount( (string) $value, $type ) );
	}

	/**
	 * Empty stays empty (use the product discount). A percent is kept between 0 and 100.
	 */
	private function clean_discount( string $raw, string $type ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$number = (float) wc_format_decimal( $raw );

		return (string) ( 'percent' === $type ? min( 100, max( 0, $number ) ) : max( 0, $number ) );
	}
}
