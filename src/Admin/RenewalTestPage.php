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

	/**
	 * One case: test customer, save the card like the checkout would, renewal charge with
	 * the real renewal code, then delete the test customer again.
	 *
	 * @param array $case Case from cases().
	 * @return array{first:string,status:string,message:string,ok:bool}
	 */
	private function run_case( string $key, array $case ): array {
		$customer = $this->stripe( $key, 'POST', 'customers', array( 'description' => 'Tasg Abo renewal test (fake data)' ) );
		$cus_id   = (string) ( $customer['id'] ?? '' );

		if ( '' === $cus_id ) {
			return array( 'first' => 'customer: ' . (string) ( $customer['error']['message'] ?? 'no answer' ), 'status' => '', 'message' => '', 'ok' => false );
		}

		$method = '';

		if ( 'attach' === $case['first'] ) {
			$pm     = $this->stripe( $key, 'POST', 'payment_methods/' . $case['card'] . '/attach', array( 'customer' => $cus_id ) );
			$method = (string) ( $pm['id'] ?? '' );
			$first  = '' !== $method ? 'card saved on the customer' : 'saving failed: ' . (string) ( $pm['error']['message'] ?? '' );
		} else {
			$body = array(
				'amount'                                     => 50,
				'currency'                                   => 'eur',
				'customer'                                   => $cus_id,
				'payment_method'                             => $case['card'],
				'confirm'                                    => 'true',
				'automatic_payment_methods[enabled]'         => 'true',
				'automatic_payment_methods[allow_redirects]' => 'never',
				'description'                                => 'Tasg Abo renewal test: first payment',
			);

			if ( 'pay_save' === $case['first'] ) {
				$body['setup_future_usage'] = 'off_session';
			}

			$pi     = $this->stripe( $key, 'POST', 'payment_intents', $body );
			$method = (string) ( $pi['payment_method'] ?? '' );
			$first  = 'first payment ' . (string) ( $pi['status'] ?? (string) ( $pi['error']['message'] ?? 'failed' ) ) . ( 'pay_save' === $case['first'] ? ', card saved for later' : ', card not saved' );
		}

		$result = array( 'status' => '', 'message' => '' );

		if ( '' !== $method ) {
			$result = ( new StripeCharger() )->charge_method( $key, $cus_id, $method, 50, 'eur', 'Tasg Abo renewal test: renewal', array( 'tasg_abo_test' => 'yes' ), 'tasg-abo-test-' . wp_generate_uuid4() );
		}

		$this->stripe( $key, 'DELETE', 'customers/' . $cus_id, array() );
		Log::info( sprintf( 'Renewal test "%s": %s, renewal %s %s', $case['card'], $first, $result['status'], $result['message'] ) );

		return array(
			'first'   => $first,
			'status'  => (string) $result['status'],
			'message' => (string) $result['message'],
			'ok'      => $result['status'] === $case['expect'],
		);
	}

}
