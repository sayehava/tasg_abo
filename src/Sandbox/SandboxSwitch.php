<?php
/**
 * The sandbox switch in the admin bar (for shop managers only), the reminder at checkout,
 * and the safety rules while it is on: only Stripe is offered (other gateways would take
 * real money), and a Stripe payment form made in the other mode is replaced.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Sandbox;

use TasgAbo\Log;

defined( 'ABSPATH' ) || exit;

final class SandboxSwitch {

	private const ACTION = 'tasg_abo_sandbox_toggle';

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'toggle' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_notice' ), 5 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'only_stripe' ), 200 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'renew_payment_form' ), 30 );
	}

	/** @param \WP_Admin_Bar $bar Admin bar. */
	public function admin_bar( $bar ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! StripeSandbox::has_test_keys() ) {
			$bar->add_node(
				array(
					'id'    => 'tasg-abo-sandbox',
					'title' => esc_html__( 'Stripe sandbox: add test keys first', 'tasg-abo' ),
					'href'  => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=stripe' ),
				)
			);
			return;
		}

		$on    = StripeSandbox::on_for_user();
		$title = $on ? __( 'Stripe sandbox: ON (click to switch off)', 'tasg-abo' ) : __( 'Stripe sandbox: off', 'tasg-abo' );
		$style = $on ? 'background:#b32d2e;color:#fff;padding:0 8px;' : '';

		$bar->add_node(
			array(
				'id'    => 'tasg-abo-sandbox',
				'title' => '<span style="' . esc_attr( $style ) . '">' . esc_html( $title ) . '</span>',
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ),
			)
		);
	}

	public function toggle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tasg-abo' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$on = ! StripeSandbox::on_for_user();
		StripeSandbox::set( get_current_user_id(), $on );
		Log::info( sprintf( 'User #%d switched the Stripe sandbox %s.', get_current_user_id(), $on ? 'on' : 'off' ) );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

}
