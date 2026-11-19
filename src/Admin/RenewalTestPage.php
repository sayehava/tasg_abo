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

	/** What a real subscription does with this renewal answer (the rules of RenewalHandler). */
	private function next_step( string $status ): string {
		switch ( $status ) {
			case 'succeeded':
				return __( 'Paid: the renewal order is completed with your renewal status and the usual emails.', 'tasg-abo' );
			case 'processing':
				return __( 'Payment still processing: the renewal order waits on hold.', 'tasg-abo' );
			case 'error':
				return __( 'Stripe not reachable: tried again a little later, no email to the customer.', 'tasg-abo' );
			case 'failed':
				return __( 'Declined: the subscription stays active, the customer gets the payment retry email with the link to change the payment method, and it is tried again after 1, 3 and 5 days.', 'tasg-abo' );
		}

		return __( 'Not charged: the test could not save the card.', 'tasg-abo' );
	}

	public function render(): void {
		$results = get_transient( 'tasg_abo_renewal_test_' . get_current_user_id() );
		$results = is_array( $results ) ? $results : array();

		echo '<h2>' . esc_html__( 'Renewal test', 'tasg-abo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tries the renewal charge in Stripe test mode with made-up data: a Stripe test customer, a Stripe test card, then the same charge a real renewal makes. Nothing is created in WooCommerce (no order, no product, no subscription) and no real money moves. The test customer is deleted afterwards.', 'tasg-abo' ) . '</p>';
		echo '<p>' . esc_html__( 'It tests the renewal. Whether the checkout saves the card is shown by one real order afterwards.', 'tasg-abo' ) . '</p>';

		if ( '' === $this->test_key() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Enter the Stripe test secret key (sk_test_...) in the Stripe plugin first: WooCommerce > Settings > Payments > Stripe. Test mode itself stays off, the shop keeps running live.', 'tasg-abo' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		submit_button( __( 'Run renewal test', 'tasg-abo' ), 'primary', 'submit', false );
		echo '</form>';

		if ( ! $results ) {
			return;
		}

		echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>' . esc_html__( 'Case', 'tasg-abo' ) . '</th><th>' . esc_html__( 'First payment', 'tasg-abo' ) . '</th><th>' . esc_html__( 'Renewal', 'tasg-abo' ) . '</th><th>' . esc_html__( 'What the subscription does', 'tasg-abo' ) . '</th><th>' . esc_html__( 'As expected', 'tasg-abo' ) . '</th></tr></thead><tbody>';

		foreach ( $this->cases() as $id => $case ) {
			if ( ! isset( $results[ $id ] ) ) {
				continue;
			}

			$r = $results[ $id ];
			echo '<tr><td>' . esc_html( $case['label'] ) . '</td><td>' . esc_html( $r['first'] ) . '</td><td><strong>' . esc_html( $r['status'] ) . '</strong><br><small>' . esc_html( $r['message'] ) . '</small></td>';
			echo '<td>' . esc_html( $this->next_step( $r['status'] ) ) . '</td><td>' . ( $r['ok'] ? '<span style="color:#008a20">&#10004; ' . esc_html__( 'yes', 'tasg-abo' ) . '</span>' : '<span style="color:#b32d2e">&#10008; ' . esc_html__( 'no', 'tasg-abo' ) . '</span>' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/** Small Stripe call for the test steps (customer, card, first payment). */
	private function stripe( string $key, string $method, string $path, array $body ): array {
		$reply = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => 45,
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				'body'    => $body,
			)
		);
		$data  = is_wp_error( $reply ) ? null : json_decode( wp_remote_retrieve_body( $reply ), true );

		return is_array( $data ) ? $data : array();
	}
}
