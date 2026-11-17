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

	/**
	 * Save the choice, make a method the default or delete one. Messages go through the
	 * WooCommerce notices, the page reloads afterwards so a refresh sends nothing twice.
	 *
	 * @param object               $sub    Subscription row.
	 * @param \WC_Payment_Token[] $tokens Saved methods of the customer.
	 */
	private static function handle_post( object $sub, array $tokens ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked right here.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), PaymentPageView::NONCE . $sub->id ) ) {
			wc_add_notice( __( 'This page was open for too long. Please try again.', 'tasg-abo' ), 'error' );
			return;
		}

		// "delete-12" or "default-12" from the small buttons, empty from the save button.
		$parts = explode( '-', isset( $_POST['token_action'] ) ? sanitize_key( wp_unslash( $_POST['token_action'] ) ) : '', 2 );
		$todo  = $parts[0];
		$id    = absint( $parts[1] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $todo ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$chosen = isset( $_POST['token'] ) ? absint( $_POST['token'] ) : 0;

			if ( ( 0 === $chosen || isset( $tokens[ $chosen ] ) ) && ( new Actions( new Repository() ) )->set_payment_token( $sub, $chosen ) ) {
				ThemePage::render( __( 'Payment method saved', 'tasg-abo' ), '<div class="tasg-abo-card"><p>' . esc_html__( 'This payment method will be used for your next order.', 'tasg-abo' ) . '</p></div>' );
			}
			return;
		}

		$token = $tokens[ $id ] ?? null;

		if ( $token && 'default' === $todo ) {
			\WC_Payment_Tokens::set_users_default( (int) $sub->user_id, $id );
			/* translators: %s: payment method, for example "Visa ending in 4242" */
			wc_add_notice( sprintf( __( '%s is now your default payment method.', 'tasg-abo' ), $token->get_display_name() ) );
		} elseif ( $token && 'delete' === $todo ) {
			if ( self::in_use( $token, (int) $sub->user_id ) ) {
				wc_add_notice( __( 'This payment method still pays for a subscription. Choose another one for it first, then you can delete it.', 'tasg-abo' ), 'error' );
			} else {
				$name = $token->get_display_name();
				\WC_Payment_Tokens::delete( $id );
				/* translators: %s: payment method, for example "Visa ending in 4242" */
				wc_add_notice( sprintf( __( '%s was deleted.', 'tasg-abo' ), $name ) );
			}
		}

		wp_safe_redirect( Links::url( $sub, 'payment' ) );
		exit;
	}

	/**
	 * Does a running subscription of the customer still charge this method, chosen or as
	 * the method of its last order? Deleting it would make the next renewal fail.
	 */
	private static function in_use( \WC_Payment_Token $token, int $user_id ): bool {
		foreach ( ( new Repository() )->for_user( $user_id ) as $sub ) {
			if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
				continue;
			}

			$chosen = (int) $sub->payment_token_id;

			if ( $chosen === $token->get_id() || ( 0 === $chosen && self::fallback_method( $sub ) === $token->get_token() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * After a payment method was added in the account area, WooCommerce sends the customer
	 * to the list of payment methods. Customers who came from the subscription page go
	 * back there instead, to choose the new method. Hooked on wp_redirect.
	 *
	 * @param string $location Redirect target.
	 */
	public static function back_after_adding( $location ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! is_string( $location ) ) {
			return $location;
		}

		$back = WC()->session->get( self::RETURN_KEY );

		if ( ! is_array( $back ) || 2 !== count( $back ) ) {
			return $location;
		}

		// Valid for half an hour, so an abandoned attempt does not catch a later visit.
		if ( time() - (int) $back[1] > 30 * MINUTE_IN_SECONDS ) {
			WC()->session->set( self::RETURN_KEY, null );
			return $location;
		}

		$list = untrailingslashit( wc_get_account_endpoint_url( 'payment-methods' ) );

		if ( 0 !== strpos( untrailingslashit( strtok( $location, '?' ) ), $list ) ) {
			return $location;
		}

		WC()->session->set( self::RETURN_KEY, null );

		return (string) $back[0];
	}
}
