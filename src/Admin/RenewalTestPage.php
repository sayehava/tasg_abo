<?php
/**
 * Renewal test: tries the renewal charge in Stripe test mode with made-up data. No
 * order, no product and no subscription is created in WooCommerce. A Stripe test
 * customer gets a Stripe test card, then the same code as a real renewal charges it,
 * and the page shows what the subscription would do with the answer. Only the Stripe
 * test secret key is used; a live key is refused.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Gateways\StripeCharger;
use TasgAbo\Log;

defined( 'ABSPATH' ) || exit;

final class RenewalTestPage {

	private const ACTION = 'tasg_abo_renewal_test';
	private const API    = 'https://api.stripe.com/v1/';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'run' ) );
	}

	/**
	 * The cases: Stripe test card, how the card is saved first, and the expected answer.
	 * pay_save = first payment that saves the card (as at checkout), pay_only = first payment
	 * without saving (the old fault), attach = card saved on the customer without a payment.
	 *
	 * @return array<string,array{label:string,card:string,first:string,expect:string}>
	 */
	private function cases(): array {
		return array(
			'normal'    => array( 'label' => __( 'Normal card, saved at the first payment', 'tasg-abo' ), 'card' => 'pm_card_visa', 'first' => 'pay_save', 'expect' => 'succeeded' ),
			'not_saved' => array( 'label' => __( 'Card not saved at the first payment (the fault of the earlier tests)', 'tasg-abo' ), 'card' => 'pm_card_visa', 'first' => 'pay_only', 'expect' => 'failed' ),
			'declined'  => array( 'label' => __( 'Bank declines the renewal', 'tasg-abo' ), 'card' => 'pm_card_chargeCustomerFail', 'first' => 'attach', 'expect' => 'failed' ),
			'auth'      => array( 'label' => __( 'Bank asks for 3D Secure on the renewal', 'tasg-abo' ), 'card' => 'pm_card_authenticationRequired', 'first' => 'attach', 'expect' => 'failed' ),
		);
	}

	/** Stripe test secret key from the Stripe plugin, empty when missing or not a test key. */
	private function test_key(): string {
		$settings = (array) get_option( 'woocommerce_stripe_settings', array() );
		$key      = trim( (string) ( $settings['test_secret_key'] ?? '' ) );

		return preg_match( '/^(sk|rk)_test_/', $key ) ? $key : '';
	}

	public function run(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tasg-abo' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$key     = $this->test_key();
		$results = array();

		if ( '' !== $key ) {
			foreach ( $this->cases() as $id => $case ) {
				$results[ $id ] = $this->run_case( $key, $case );
			}
		}

		set_transient( 'tasg_abo_renewal_test_' . get_current_user_id(), $results, HOUR_IN_SECONDS );
		wp_safe_redirect( SettingsHub::url( 'renewal-test', array( 'ran' => '1' ) ) );
		exit;
	}

}
