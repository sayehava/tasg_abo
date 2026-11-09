<?php
/**
 * Subscription fields on the product edit screen.
 *
 * Meta keys:
 *   _tasg_abo_enabled        yes|no
 *   _tasg_abo_intervals      comma separated weeks, e.g. "2,4,8"
 *   _tasg_abo_discount_type  percent|fixed
 *   _tasg_abo_discount_value decimal, 0 means no discount
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

defined( 'ABSPATH' ) || exit;

final class ProductSettings {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );
	}

	public function render_fields(): void {
		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_tasg_abo_enabled',
				'label'       => __( 'Subscription', 'tasg-abo' ),
				'description' => __( 'Let customers subscribe to this product.', 'tasg-abo' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_tasg_abo_intervals',
				'label'       => __( 'Intervals (weeks)', 'tasg-abo' ),
				'description' => __( 'Comma separated list the customer can choose from, for example 2,4,8.', 'tasg-abo' ),
				'desc_tip'    => true,
				'placeholder' => '2,4,8',
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
				'description' => __( 'Taken off the price of every subscription order. Leave empty for no discount.', 'tasg-abo' ),
				'desc_tip'    => true,
				'data_type'   => 'price',
			)
		);

		echo '</div>';
	}

	/**
	 * @param \WC_Product $product Product being saved.
	 */
	public function save_fields( $product ): void {
		// Nonce and capability are already verified by WooCommerce before this hook fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$enabled = isset( $_POST['_tasg_abo_enabled'] ) ? 'yes' : 'no';

		$raw       = isset( $_POST['_tasg_abo_intervals'] ) ? wp_unslash( $_POST['_tasg_abo_intervals'] ) : '';
		$intervals = self::sanitize_intervals( $raw );

		$type = isset( $_POST['_tasg_abo_discount_type'] ) ? sanitize_key( wp_unslash( $_POST['_tasg_abo_discount_type'] ) ) : 'percent';
		$type = in_array( $type, array( 'percent', 'fixed' ), true ) ? $type : 'percent';

		$value = isset( $_POST['_tasg_abo_discount_value'] ) ? wc_format_decimal( wp_unslash( $_POST['_tasg_abo_discount_value'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'percent' === $type && '' !== $value ) {
			$value = (string) min( 100, max( 0, (float) $value ) );
		}

		$product->update_meta_data( '_tasg_abo_enabled', $enabled );
		$product->update_meta_data( '_tasg_abo_intervals', $intervals );
		$product->update_meta_data( '_tasg_abo_discount_type', $type );
		$product->update_meta_data( '_tasg_abo_discount_value', $value );
	}

	/**
	 * Keep only positive whole numbers, unique and sorted.
	 */
	public static function sanitize_intervals( string $raw ): string {
		$weeks = array();

		foreach ( explode( ',', $raw ) as $part ) {
			$week = (int) trim( $part );
			if ( $week > 0 && $week <= 52 ) {
				$weeks[ $week ] = $week;
			}
		}

		ksort( $weeks );

		return implode( ',', $weeks );
	}
}
