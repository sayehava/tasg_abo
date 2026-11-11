<?php
/**
 * "Subscribe and save" controls on the product page.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Pricing;
use TasgAbo\Settings;

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

		$plans = Pricing::plans( $product );

		echo '<div class="tasg-abo-toggle">';
		echo '<label><input type="checkbox" name="tasg_abo_subscribe" value="1"> ' . esc_html__( 'Subscribe and save', 'tasg-abo' ) . '</label> ';

		$default_id = Pricing::default_plan_id( $plans, $product );

		echo '<label>' . esc_html__( 'Plan', 'tasg-abo' ) . ' <select name="tasg_abo_plan">';
		foreach ( $plans as $id => $plan ) {
			$price = html_entity_decode( wp_strip_all_tags( wc_price( Pricing::subscriber_price( $product, (int) $id ) ) ), ENT_QUOTES, 'UTF-8' );
			$name  = '' !== $plan->name ? $plan->name . ' (' . $plan->period_label() . ')' : $plan->period_label();

			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( (int) $id === $default_id, true, false ) . '>' . esc_html( $name . ': ' . $price ) . '</option>';
		}
		echo '</select></label>';

		if ( Settings::admin_only() ) {
			echo '<p class="tasg-abo-note"><strong>' . esc_html__( 'Test phase: only shop managers can see this option.', 'tasg-abo' ) . '</strong></p>';
		}

		echo '<p class="tasg-abo-note">' . esc_html__( 'A subscription is for one piece. Cancel, skip or pause any time.', 'tasg-abo' ) . '</p>';
		echo '</div>';
	}
}
