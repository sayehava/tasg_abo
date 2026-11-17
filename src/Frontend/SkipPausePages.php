<?php
/**
 * The skip and pause pages of the email links.
 *
 * Skip leaves out exactly the next order, everything after it stays as planned.
 * Pause stops orders and charges for a length the customer picks from the lengths
 * the shop owner offers; afterwards the subscription continues by itself.
 * Both pages show the real dates before anything is changed. The change happens
 * only on the button (POST), so mail scanners opening a link change nothing.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Plans\Period;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class SkipPausePages {

	/**
	 * A paused subscription: nothing to skip or pause, but it can continue now.
	 */
	private static function paused_or_closed( object $sub, string $title ): void {
		$text = ! empty( $sub->pause_until )
			/* translators: %s: date of the first order after the pause */
			? sprintf( __( 'Your subscription is paused. It continues by itself with the order on %s.', 'tasg-abo' ), ThemePage::date( strtotime( $sub->pause_until . ' UTC' ) ) )
			: __( 'Your subscription is paused.', 'tasg-abo' );

		$html  = ThemePage::summary( $sub ) . '<div class="tasg-abo-card"><p>' . esc_html( $text ) . '</p>';
		$html .= '<div class="tasg-abo-actions"><a class="button alt" href="' . esc_url( Links::url( $sub, 'continue' ) ) . '">' . esc_html__( 'End the pause now', 'tasg-abo' ) . '</a>' . ThemePage::back_link() . '</div></div>';

		ThemePage::render( $title, $html );
	}

	/**
	 * Opening form tag with the hidden fields every link form needs.
	 */
	public static function form_start( object $sub, string $action ): string {
		return '<form method="post" action="">'
			. '<input type="hidden" name="tasg_abo" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';
	}

	/**
	 * Skip and pause exist for running subscriptions after the minimum term.
	 * Shows why not and stops, or returns true.
	 */
	private static function open( object $sub, string $title ): bool {
		if ( 'active' !== $sub->status && 'paused' !== $sub->status ) {
			ThemePage::render( $title, '<div class="tasg-abo-card"><p>' . esc_html__( 'This subscription has ended, so there is nothing to change.', 'tasg-abo' ) . '</p></div>' );
		}

		if ( ( new Actions( new Repository() ) )->in_term( $sub ) ) {
			$html = ThemePage::summary( $sub ) . '<div class="tasg-abo-card"><p>' . esc_html( sprintf(
				/* translators: %s: date the minimum term ends */
				__( 'Your subscription has a minimum term until %s. Skipping and pausing are possible after that date.', 'tasg-abo' ),
				ThemePage::date( strtotime( $sub->term_ends . ' UTC' ) )
			) ) . '</p></div>';
			ThemePage::render( $title, $html );
		}

		return 'active' === $sub->status;
	}
}
