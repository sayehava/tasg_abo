<?php
/**
 * Customer page of the payment link: choose which saved payment method pays for the
 * subscription, add a new one, make one the default or delete an old one. Payment
 * methods are added in the WooCommerce account area, this plugin never sees card data.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class PaymentPage {

	/** Session entry: where to go after adding a payment method, and since when. */
	private const RETURN_KEY = 'tasg_abo_payment_return';

	public static function register(): void {
		add_filter( 'wp_redirect', array( self::class, 'back_after_adding' ), 20 );
	}

	public static function show( object $sub, bool $post ): void {
		$title = __( 'Change your payment method', 'tasg-abo' );

		// Saved payment methods belong to the customer account: log in right here.
		if ( ! is_user_logged_in() || get_current_user_id() !== (int) $sub->user_id ) {
			ob_start();
			woocommerce_login_form( array( 'redirect' => Links::url( $sub, 'payment' ) ) );
			ThemePage::render( $title, '<div class="tasg-abo-card">' . ob_get_clean() . '</div>', __( 'Please log in to your account to choose the payment method for your subscription.', 'tasg-abo' ) );
		}

		if ( 0 !== strpos( (string) $sub->gateway, 'stripe' ) || ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			ThemePage::render( __( 'Nothing changed', 'tasg-abo' ), '<div class="tasg-abo-card"><p>' . esc_html__( 'The payment method of this subscription cannot be changed here.', 'tasg-abo' ) . '</p></div>' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only starts the add flow of WooCommerce.
		if ( isset( $_GET['add'] ) && WC()->session ) {
			WC()->session->set( self::RETURN_KEY, array( Links::url( $sub, 'payment' ), time() ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'add-payment-method' ) );
			exit;
		}

		$tokens = self::tokens( (int) $sub->user_id );

		if ( $post ) {
			self::handle_post( $sub, $tokens );
		}

		ThemePage::render( $title, PaymentPageView::html( $sub, $tokens, self::fallback_method( $sub ) ) );
	}

	/**
	 * Saved Stripe payment methods of the customer, by token id.
	 *
	 * @return \WC_Payment_Token[]
	 */
	private static function tokens( int $user_id ): array {
		$tokens = array();

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
			if ( 0 === strpos( (string) $token->get_gateway_id(), 'stripe' ) ) {
				$tokens[ $token->get_id() ] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * Stripe payment method that is charged when no saved method is chosen: the one of
	 * the last order, or of the first order (the same rule as the renewal charge).
	 */
	public static function fallback_method( object $sub ): string {
		foreach ( array( (int) $sub->last_order_id, (int) $sub->origin_order_id ) as $order_id ) {
			$order = $order_id ? wc_get_order( $order_id ) : false;

			if ( $order && '' !== (string) $order->get_meta( '_stripe_source_id' ) ) {
				return (string) $order->get_meta( '_stripe_source_id' );
			}
		}

		return '';
	}

}
