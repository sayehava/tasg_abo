<?php
/**
 * Markup of the payment page: the saved payment methods as option cards, each with
 * "make default" and "delete", and the button to add a new one.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Subscriptions\Links;

defined( 'ABSPATH' ) || exit;

final class PaymentPageView {

	public const NONCE = 'tasg_abo_payment_';

	/**
	 * @param object               $sub      Subscription row.
	 * @param \WC_Payment_Token[] $tokens   Saved Stripe methods of the customer.
	 * @param string               $fallback Stripe method of the last order.
	 */
	public static function html( object $sub, array $tokens, string $fallback ): string {
		$current = (int) $sub->payment_token_id;
		$last    = '';

		foreach ( $tokens as $token ) {
			if ( '' !== $fallback && $token->get_token() === $fallback ) {
				$last = $token->get_display_name();
			}
		}

		$html  = ThemePage::summary( $sub, false ) . '<div class="tasg-abo-card">';
		$html .= SkipPausePages::form_start( $sub, 'payment' ) . wp_nonce_field( self::NONCE . $sub->id, '_wpnonce', false, false );
		$html .= '<p class="tasg-abo-product">' . esc_html__( 'Which payment method pays for your next orders?', 'tasg-abo' ) . '</p><div class="tasg-abo-options">';

		$note  = '' !== $last ? $last : __( 'The card or account you paid with last time.', 'tasg-abo' );
		$html .= self::option( 0, $current, __( 'The payment method of my last order', 'tasg-abo' ), $note );

		foreach ( $tokens as $id => $token ) {
			$html .= '<div class="tasg-abo-token">';
			$html .= self::option( (int) $id, $current, $token->get_display_name(), $token->is_default() ? __( 'Your default payment method', 'tasg-abo' ) : '' );
			$html .= '<div class="tasg-abo-token-actions">';

			if ( ! $token->is_default() ) {
				$html .= self::action_button( 'default', (int) $id, __( 'Make default', 'tasg-abo' ) );
			}

			$html .= self::action_button( 'delete', (int) $id, __( 'Delete', 'tasg-abo' ), __( 'Delete this payment method?', 'tasg-abo' ) );
			$html .= '</div></div>';
		}

		$html .= '</div><div class="tasg-abo-actions"><button type="submit" class="button alt single_add_to_cart_button my-atc-button">' . esc_html__( 'Save payment method', 'tasg-abo' ) . '</button></div></form>';

		// Own form for the small buttons (they point to it with form=""), so Enter in the main form saves.
		$html .= str_replace( '<form ', '<form id="tasg-abo-token-form" ', SkipPausePages::form_start( $sub, 'payment' ) ) . wp_nonce_field( self::NONCE . $sub->id, '_wpnonce', false, false ) . '</form>';

		$add   = add_query_arg( 'add', '1', Links::url( $sub, 'payment' ) );
		$html .= '<div class="tasg-abo-add"><a class="button alt my-atc-button tasg-secondary-atc-button" href="' . esc_url( $add ) . '">' . esc_html__( 'Add a new payment method', 'tasg-abo' ) . '</a>';
		$html .= '<p class="tasg-abo-note">' . esc_html__( 'You enter the new card or account securely with our payment provider Stripe. Afterwards you come back here and can choose it.', 'tasg-abo' ) . '</p></div>';

		return $html . '<p class="tasg-abo-note">' . ThemePage::back_link() . '</p></div>';
	}

	private static function option( int $id, int $current, string $name, string $note ): string {
		$html  = '<label class="tasg-abo-option"><input type="radio" name="token" value="' . esc_attr( (string) $id ) . '"' . checked( $current, $id, false ) . '>';
		$html .= '<span><strong>' . esc_html( $name ) . '</strong>';

		if ( '' !== $note ) {
			$html .= '<small>' . esc_html( $note ) . '</small>';
		}

		return $html . '</span></label>';
	}

	/**
	 * Small text button of the token form. It sends its action and token id as "delete-12".
	 */
	private static function action_button( string $todo, int $id, string $label, string $confirm = '' ): string {
		$onclick = '' !== $confirm ? ' onclick="return window.confirm(\'' . esc_js( $confirm ) . '\');"' : '';

		return '<button type="submit" form="tasg-abo-token-form" class="tasg-abo-text-button" name="token_action" value="' . esc_attr( $todo . '-' . $id ) . '"' . $onclick . '>'
			. esc_html( $label ) . '</button>';
	}
}
