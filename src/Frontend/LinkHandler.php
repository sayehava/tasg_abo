<?php
/**
 * Landing page for the cancel, skip, pause and continue links in emails.
 *
 * Opening a link only shows a confirmation button. The change happens when the
 * button is pressed (POST), so mail scanners that open links cannot cancel
 * anything by accident.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Pricing;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Address;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class LinkHandler {

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_REQUEST['tasg_abo'], $_REQUEST['key'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['tasg_abo'] ) );
		$key    = sanitize_text_field( wp_unslash( $_REQUEST['key'] ) );
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable WordPress.Security.NonceVerification

		nocache_headers();

		$repository = new Repository();
		$sub        = in_array( $action, Links::ACTIONS, true ) ? $repository->find_by_key( $key ) : null;

		if ( ! $sub ) {
			$this->page( __( 'Link not valid', 'tasg-abo' ), '<p>' . esc_html__( 'This link is not valid any more.', 'tasg-abo' ) . '</p>' );
		}

		// Links from older emails open the cancel page, where every cancellation happens now.
		if ( 'cancel' === $action ) {
			$page = \TasgAbo\Cancellation\CancelPage::url( $sub );

			if ( '' !== $page ) {
				wp_safe_redirect( $page );
				exit;
			}
		}

		// Options the shop owner switched off in the settings.
		if ( ! Settings::customer_may( $action ) ) {
			$this->page( __( 'Not available', 'tasg-abo' ), '<p>' . esc_html__( 'The shop has switched this option off. Please contact us if you need help.', 'tasg-abo' ) . '</p>' );
		}

		if ( 'address' === $action ) {
			$this->address_form( $sub, $post );
		}

		if ( 'plan' === $action ) {
			$this->plan_form( $sub, $post );
		}

		if ( 'payment' === $action ) {
			$this->payment_form( $sub, $post );
		}

		if ( ! $post ) {
			$this->confirm( $sub, $action );
		}

		$done = ( new Actions( $repository ) )->run( $sub, $action );

		$this->page(
			$done ? $this->done_title( $action, $sub ) : __( 'Nothing changed', 'tasg-abo' ),
			'<p>' . esc_html( $done ? $this->done_text( $action, $sub ) : $this->failed_text( $action, $sub ) ) . '</p>'
		);
	}

	/**
	 * Lets the customer correct the shipping address of the next order.
	 * The correction is stored on the subscription and used by the next renewal.
	 */
	private function address_form( object $sub, bool $post ): void {
		if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			$this->page( __( 'Nothing changed', 'tasg-abo' ), '<p>' . esc_html__( 'This subscription cannot be changed this way right now.', 'tasg-abo' ) . '</p>' );
		}

		$values = Address::shipping( $sub );
		$errors = array();

		if ( $post ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$values = array_merge( $values, $this->posted_address() );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$errors = $this->address_errors( $values );

			if ( ! $errors ) {
				$saved = array_intersect_key( $values, array_flip( Address::FIELDS ) );
				( new Repository() )->update( (int) $sub->id, array( 'address_override' => wp_json_encode( $saved ) ) );
				\TasgAbo\Log::info( sprintf( 'Subscription #%d: shipping address for the next order corrected.', (int) $sub->id ) );

				$this->page(
					__( 'Address saved', 'tasg-abo' ),
					'<p>' . esc_html__( 'Your next order will be sent to this address:', 'tasg-abo' ) . '</p><p>' . nl2br( esc_html( Address::formatted( $values ) ) ) . '</p>'
				);
			}
		}

		$labels = array(
			'first_name' => __( 'First name', 'tasg-abo' ),
			'last_name'  => __( 'Last name', 'tasg-abo' ),
			'company'    => __( 'Company (optional)', 'tasg-abo' ),
			'address_1'  => __( 'Street and number', 'tasg-abo' ),
			'address_2'  => __( 'Address line 2 (optional)', 'tasg-abo' ),
			'postcode'   => __( 'Postcode', 'tasg-abo' ),
			'city'       => __( 'City', 'tasg-abo' ),
			'state'      => __( 'State or region (optional)', 'tasg-abo' ),
		);

		$html = '';
		foreach ( $errors as $error ) {
			$html .= '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}

		$html .= '<form method="post" action="">';
		$html .= '<input type="hidden" name="tasg_abo" value="address"><input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';

		foreach ( $labels as $field => $label ) {
			$html .= '<p><label>' . esc_html( $label ) . '<br><input type="text" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) ( $values[ $field ] ?? '' ) ) . '" style="width:100%"></label></p>';
		}

		$html .= '<p><label>' . esc_html__( 'Country', 'tasg-abo' ) . '<br><select name="country" style="width:100%">';
		foreach ( WC()->countries->get_shipping_countries() as $code => $name ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( (string) ( $values['country'] ?? '' ), $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		$html .= '</select></label></p>';
		$html .= '<button type="submit" class="button">' . esc_html__( 'Save address', 'tasg-abo' ) . '</button></form>';

		$this->page( __( 'Check your shipping address', 'tasg-abo' ), $html );
	}

	/**
	 * @return array Cleaned address fields from the form.
	 */
	private function posted_address(): array {
		$posted = array();

		foreach ( Address::FIELDS as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		return $posted;
	}

	/**
	 * @return string[] Messages, empty when the address is fine.
	 */
	private function address_errors( array $address ): array {
		$errors = array();

		foreach ( array( 'first_name', 'last_name', 'address_1', 'postcode', 'city', 'country' ) as $field ) {
			if ( '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				$errors[] = __( 'Please fill in all fields that are not marked optional.', 'tasg-abo' );
				break;
			}
		}

		if ( ! isset( WC()->countries->get_shipping_countries()[ $address['country'] ?? '' ] ) ) {
			$errors[] = __( 'We cannot ship to this country.', 'tasg-abo' );
		} elseif ( '' !== ( $address['postcode'] ?? '' ) && ! \WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) {
			$errors[] = __( 'The postcode is not valid for this country.', 'tasg-abo' );
		}

		return $errors;
	}

	/**
	 * Lets the customer switch to another plan the product offers.
	 */
	private function plan_form( object $sub, bool $post ): void {
		$product = wc_get_product( (int) $sub->product_id );
		$plans   = $product ? Pricing::plans( $product ) : array();

		if ( ! $plans || ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			$this->page( __( 'Nothing changed', 'tasg-abo' ), '<p>' . esc_html__( 'This subscription cannot be changed this way right now.', 'tasg-abo' ) . '</p>' );
		}

		if ( $post ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chosen = isset( $_POST['plan'] ) ? absint( $_POST['plan'] ) : 0;

			if ( isset( $plans[ $chosen ] ) && ( new Actions( new Repository() ) )->change_plan( $sub, $plans[ $chosen ] ) ) {
				$this->page( __( 'Plan changed', 'tasg-abo' ), '<p>' . esc_html__( 'Your plan was changed. You will get a reminder before the next order, with the new date and price.', 'tasg-abo' ) . '</p>' );
			}
		}

		$html  = '<form method="post" action="">';
		$html .= '<input type="hidden" name="tasg_abo" value="plan"><input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';

		foreach ( $plans as $id => $plan ) {
			$price = html_entity_decode( wp_strip_all_tags( wc_price( Pricing::subscriber_price( $product, (int) $id ) ) ), ENT_QUOTES, 'UTF-8' );
			$label = $plan->display_name();
			$name  = '' !== $label ? $label . ' (' . $plan->period_label() . ')' : $plan->period_label();
			$now   = (int) $sub->plan_id === (int) $id ? ' ' . __( '(current)', 'tasg-abo' ) : '';

			$html .= '<p><label><input type="radio" name="plan" value="' . esc_attr( (string) $id ) . '"' . checked( (int) $sub->plan_id === (int) $id, true, false ) . '> ' . esc_html( $name . ': ' . $price . $now ) . '</label></p>';
		}

		$html .= '<button type="submit" class="button">' . esc_html__( 'Save plan', 'tasg-abo' ) . '</button></form>';

		$this->page( __( 'Choose a new plan', 'tasg-abo' ), $html );
	}

	/**
	 * Lets the logged in customer choose which saved Stripe payment method is used.
	 * Cards are added in the normal WooCommerce account area, this plugin never sees card data.
	 */
	private function payment_form( object $sub, bool $post ): void {
		if ( ! is_user_logged_in() || get_current_user_id() !== (int) $sub->user_id ) {
			wp_safe_redirect( wp_login_url( Links::url( $sub, 'payment' ) ) );
			exit;
		}

		if ( 0 !== strpos( (string) $sub->gateway, 'stripe' ) || ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			$this->page( __( 'Nothing changed', 'tasg-abo' ), '<p>' . esc_html__( 'The payment method of this subscription cannot be changed here.', 'tasg-abo' ) . '</p>' );
		}

		$tokens = array();
		foreach ( \WC_Payment_Tokens::get_customer_tokens( (int) $sub->user_id ) as $token ) {
			if ( 0 === strpos( (string) $token->get_gateway_id(), 'stripe' ) ) {
				$tokens[ $token->get_id() ] = $token;
			}
		}

		if ( $post ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chosen = isset( $_POST['token'] ) ? absint( $_POST['token'] ) : 0;

			if ( ( 0 === $chosen || isset( $tokens[ $chosen ] ) ) && ( new Actions( new Repository() ) )->set_payment_token( $sub, $chosen ) ) {
				$this->page( __( 'Payment method saved', 'tasg-abo' ), '<p>' . esc_html__( 'This payment method will be used for your next order.', 'tasg-abo' ) . '</p>' );
			}
		}

		$html  = '<form method="post" action="">';
		$html .= '<input type="hidden" name="tasg_abo" value="payment"><input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';
		$html .= '<p><label><input type="radio" name="token" value="0"' . checked( (int) $sub->payment_token_id, 0, false ) . '> ' . esc_html__( 'The payment method of my last order', 'tasg-abo' ) . '</label></p>';

		foreach ( $tokens as $id => $token ) {
			$html .= '<p><label><input type="radio" name="token" value="' . esc_attr( (string) $id ) . '"' . checked( (int) $sub->payment_token_id === (int) $id, true, false ) . '> ' . esc_html( $token->get_display_name() ) . '</label></p>';
		}

		$html .= '<p><a href="' . esc_url( wc_get_account_endpoint_url( 'add-payment-method' ) ) . '">' . esc_html__( 'Add a new payment method', 'tasg-abo' ) . '</a></p>';
		$html .= '<button type="submit" class="button">' . esc_html__( 'Save payment method', 'tasg-abo' ) . '</button></form>';

		$this->page( __( 'Change your payment method', 'tasg-abo' ), $html );
	}

	private function confirm( object $sub, string $action ): void {
		$product = wc_get_product( (int) $sub->product_id );
		$name    = $product ? $product->get_name() : '';

		$labels = array(
			'cancel'   => array( __( 'Cancel your subscription?', 'tasg-abo' ), __( 'Yes, cancel', 'tasg-abo' ) ),
			'skip'     => array( __( 'Skip your next order?', 'tasg-abo' ), __( 'Yes, skip', 'tasg-abo' ) ),
			'pause'    => array( __( 'Pause your subscription?', 'tasg-abo' ), __( 'Yes, pause', 'tasg-abo' ) ),
			'continue' => array( __( 'Continue your subscription?', 'tasg-abo' ), __( 'Yes, continue', 'tasg-abo' ) ),
			'keep'     => array( __( 'Keep your subscription?', 'tasg-abo' ), __( 'Yes, keep it', 'tasg-abo' ) ),
		);

		$html = '<p>' . esc_html( $name ) . '</p>';

		// Inside the minimum term a cancellation only takes effect when the term is over.
		if ( 'cancel' === $action && ( new Actions( new Repository() ) )->in_term( $sub ) ) {
			/* translators: %s: date the minimum term ends */
			$html .= '<p>' . esc_html( sprintf( __( 'Your subscription has a minimum term until %s. If you cancel now, it ends then and you will be charged for the orders until that date.', 'tasg-abo' ), $this->term_date( $sub ) ) ) . '</p>';
		}

		$html .= '<form method="post" action="">';
		$html .= '<input type="hidden" name="tasg_abo" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';
		$html .= '<button type="submit" class="button">' . esc_html( $labels[ $action ][1] ) . '</button>';
		$html .= '</form>';

		$this->page( $labels[ $action ][0], $html );
	}

	private function term_date( object $sub ): string {
		return wp_date( get_option( 'date_format' ), strtotime( $sub->term_ends . ' UTC' ) );
	}

	/**
	 * Why nothing changed, with the real reason for skip and pause inside the term.
	 */
	private function failed_text( string $action, object $sub ): string {
		if ( in_array( $action, array( 'skip', 'pause' ), true ) && ( new Actions( new Repository() ) )->in_term( $sub ) ) {
			/* translators: %s: date the minimum term ends */
			return sprintf( __( 'This is not possible during the minimum term, which runs until %s.', 'tasg-abo' ), $this->term_date( $sub ) );
		}

		return __( 'This subscription cannot be changed this way right now.', 'tasg-abo' );
	}

	private function done_title( string $action, object $sub ): string {
		// The cancellation was only noted when the minimum term is still running.
		if ( 'cancel' === $action && ( new Actions( new Repository() ) )->in_term( $sub ) ) {
			return __( 'Cancellation noted', 'tasg-abo' );
		}

		$titles = array(
			'keep'     => __( 'Subscription kept', 'tasg-abo' ),
			'cancel'   => __( 'Subscription cancelled', 'tasg-abo' ),
			'skip'     => __( 'Order skipped', 'tasg-abo' ),
			'pause'    => __( 'Subscription paused', 'tasg-abo' ),
			'continue' => __( 'Subscription continued', 'tasg-abo' ),
		);

		return $titles[ $action ];
	}

	private function done_text( string $action, object $sub ): string {
		if ( 'cancel' === $action && ( new Actions( new Repository() ) )->in_term( $sub ) ) {
			/* translators: %s: date the minimum term ends */
			return sprintf( __( 'Your subscription ends with the minimum term on %s. Orders continue until then and you will not be charged after that.', 'tasg-abo' ), $this->term_date( $sub ) );
		}

		$texts = array(
			'keep'     => __( 'Thank you. Your cancellation is withdrawn and your subscription continues.', 'tasg-abo' ),
			'cancel'   => __( 'Your subscription is cancelled. You will not be charged again.', 'tasg-abo' ),
			'skip'     => __( 'We will skip one order and carry on after that.', 'tasg-abo' ),
			'pause'    => __( 'Your subscription is paused. We will ask you before the pause ends whether to continue or cancel.', 'tasg-abo' ),
			'continue' => __( 'Thank you. Your subscription continues and we will send you a reminder before the next order.', 'tasg-abo' ),
		);

		return $texts[ $action ];
	}

	/**
	 * Simple standalone page that works with any theme.
	 * Every dynamic value in $html is escaped where it is built, and the
	 * form markup must stay intact, so it is not run through wp_kses.
	 */
	private function page( string $title, string $html ): void {
		wp_die( $html, esc_html( $title ), array( 'response' => 200, 'back_link' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
