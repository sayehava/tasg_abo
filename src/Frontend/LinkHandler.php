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

use TasgAbo\Subscriptions\Actions;
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

		if ( ! $post ) {
			$this->confirm( $sub, $action );
		}

		$done = ( new Actions( $repository ) )->run( $sub, $action );

		$this->page(
			$done ? $this->done_title( $action ) : __( 'Nothing changed', 'tasg-abo' ),
			'<p>' . esc_html( $done ? $this->done_text( $action ) : __( 'This subscription cannot be changed this way right now.', 'tasg-abo' ) ) . '</p>'
		);
	}

	private function confirm( object $sub, string $action ): void {
		$product = wc_get_product( (int) $sub->product_id );
		$name    = $product ? $product->get_name() : '';

		$labels = array(
			'cancel'   => array( __( 'Cancel your subscription?', 'tasg-abo' ), __( 'Yes, cancel', 'tasg-abo' ) ),
			'skip'     => array( __( 'Skip your next order?', 'tasg-abo' ), __( 'Yes, skip', 'tasg-abo' ) ),
			'pause'    => array( __( 'Pause your subscription?', 'tasg-abo' ), __( 'Yes, pause', 'tasg-abo' ) ),
			'continue' => array( __( 'Continue your subscription?', 'tasg-abo' ), __( 'Yes, continue', 'tasg-abo' ) ),
		);

		$html  = '<p>' . esc_html( $name ) . '</p>';
		$html .= '<form method="post" action="">';
		$html .= '<input type="hidden" name="tasg_abo" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="key" value="' . esc_attr( $sub->cancel_key ) . '">';
		$html .= '<button type="submit" class="button">' . esc_html( $labels[ $action ][1] ) . '</button>';
		$html .= '</form>';

		$this->page( $labels[ $action ][0], $html );
	}

	private function done_title( string $action ): string {
		$titles = array(
			'cancel'   => __( 'Subscription cancelled', 'tasg-abo' ),
			'skip'     => __( 'Order skipped', 'tasg-abo' ),
			'pause'    => __( 'Subscription paused', 'tasg-abo' ),
			'continue' => __( 'Subscription continued', 'tasg-abo' ),
		);

		return $titles[ $action ];
	}

	private function done_text( string $action ): string {
		$texts = array(
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
