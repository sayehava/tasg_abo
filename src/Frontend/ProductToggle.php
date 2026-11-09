<?php
/**
 * "Subscribe and save" controls on the product page.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Pricing;

defined( 'ABSPATH' ) || exit;

final class ProductToggle {

	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ) );
	}

	public function render(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! Pricing::is_enabled( $product ) ) {
			return;
		}

		$price = wc_price( Pricing::subscriber_price( $product ) );

		echo '<div class="tasg-abo-toggle">';
		echo '<label><input type="checkbox" name="tasg_abo_subscribe" value="1"> ';
		/* translators: %s: discounted price */
		echo esc_html( sprintf( __( 'Subscribe and pay only %s', 'tasg-abo' ), wp_strip_all_tags( $price ) ) );
		echo '</label>';

		echo '<label> ' . esc_html__( 'Deliver every', 'tasg-abo' ) . ' ';
		echo '<select name="tasg_abo_interval">';
		foreach ( Pricing::intervals( $product ) as $weeks ) {
			/* translators: %d: number of weeks */
			$label = sprintf( _n( '%d week', '%d weeks', $weeks, 'tasg-abo' ), $weeks );
			echo '<option value="' . esc_attr( (string) $weeks ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<p class="tasg-abo-note">' . esc_html__( 'Cancel, skip or pause any time.', 'tasg-abo' ) . '</p>';
		echo '</div>';
	}
}
