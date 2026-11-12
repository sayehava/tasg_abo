<?php
/**
 * The cancellation form. Three ways in, one page:
 * - a logged in customer sees the own running subscriptions,
 * - a link with the secret key of a subscription (reminder email, dashboard button) shows
 *   that subscription without login,
 * - everyone else enters the order number and the questions chosen in the settings.
 * One click on "Cancel contract now" cancels, and a confirmation email follows at once.
 * The page offers nothing but the cancellation: no discounts, no pause, no questions why.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cancellation;

use TasgAbo\Emails\CancelConfirmationEmail;
use TasgAbo\Log;
use TasgAbo\Plans\Period;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class CancelForm {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function render(): string {
		ob_start();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['tasg_abo_cancel_go'] ) ) {
			$this->handle_post();
		} else {
			$this->show_form();
		}

		return (string) ob_get_clean();
	}

	private function is_open( object $sub ): bool {
		return in_array( $sub->status, array( 'active', 'paused' ), true );
	}

	private function notice( string $text, bool $error = true ): void {
		echo '<div class="' . ( $error ? 'woocommerce-error' : 'woocommerce-info' ) . '" role="alert">' . esc_html( $text ) . '</div>';
	}

	/**
	 * A posted value, empty if missing.
	 */
	private function posted( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * The right form for this visitor.
	 */
	private function show_form( string $key = '' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = '' !== $key ? $key : ( isset( $_GET['tasg_abo_key'] ) ? sanitize_text_field( wp_unslash( $_GET['tasg_abo_key'] ) ) : '' );

		if ( '' !== $key ) {
			$sub = $this->repository->find_by_key( $key );

			if ( $sub && $this->is_open( $sub ) ) {
				$this->list_form( array( $sub ), $key );
				return;
			}

			$this->notice( __( 'This link is not valid any more. You can still cancel with your order number below.', 'tasg-abo' ) );
		}

		if ( is_user_logged_in() ) {
			$open = array_filter( $this->repository->for_user( get_current_user_id() ), array( $this, 'is_open' ) );

			if ( $open ) {
				$this->list_form( $open, '' );
			} else {
				$this->notice( __( 'You have no running subscription.', 'tasg-abo' ), false );
			}
			return;
		}

		$this->guest_form();
	}
}
