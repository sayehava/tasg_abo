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

	private function title( object $sub ): string {
		$product = wc_get_product( (int) $sub->product_id );

		return ( $product ? $product->get_name() : '' ) . ' (' . Period::row_label( $sub ) . ')';
	}

	private function contract_line( object $sub ): string {
		$order = wc_get_order( (int) $sub->origin_order_id );

		/* translators: 1: subscription number, 2: number of the first order */
		return sprintf( __( 'Subscription no. %1$d, first order no. %2$s', 'tasg-abo' ), (int) $sub->id, $order ? $order->get_order_number() : (string) (int) $sub->origin_order_id );
	}

	private function date( string $utc ): string {
		return wp_date( get_option( 'date_format' ), strtotime( $utc . ' UTC' ) );
	}

	private function effect_text( object $sub ): string {
		if ( ( new Actions( $this->repository ) )->in_term( $sub ) ) {
			/* translators: %s: date the minimum term ends */
			return sprintf( __( 'If you cancel now, the contract ends with the minimum term on %s. Orders continue until then.', 'tasg-abo' ), $this->date( $sub->term_ends ) );
		}

		return __( 'If you cancel now, the contract ends today and you will not be charged again.', 'tasg-abo' );
	}

	/**
	 * Type of cancellation and the reason for an extraordinary one.
	 */
	private function type_fields(): void {
		$type = 'extraordinary' === $this->posted( 'cancel_type' ) ? 'extraordinary' : 'ordinary';

		echo '<p><strong>' . esc_html__( 'Type of cancellation', 'tasg-abo' ) . '</strong><br>';
		echo '<label><input type="radio" name="cancel_type" value="ordinary"' . checked( $type, 'ordinary', false ) . '> ' . esc_html__( 'Ordinary cancellation', 'tasg-abo' ) . '</label><br>';
		echo '<label><input type="radio" name="cancel_type" value="extraordinary"' . checked( $type, 'extraordinary', false ) . '> ' . esc_html__( 'Extraordinary cancellation (please give the reason)', 'tasg-abo' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Reason (only for an extraordinary cancellation)', 'tasg-abo' ) . '<br>';
		echo '<textarea name="cancel_reason" rows="3" class="input-text" style="width:100%">' . esc_textarea( $this->posted( 'cancel_reason' ) ) . '</textarea></label></p>';
	}

	/**
	 * The contracts of a logged in customer, or the one behind a secret link, each with its button.
	 *
	 * @param object[] $subs Subscriptions to show.
	 * @param string   $key  Secret key from the link, empty without one.
	 */
	private function list_form( array $subs, string $key ): void {
		echo '<form method="post" action="" class="tasg-abo-cancel">';
		wp_nonce_field( 'tasg_abo_cancel', 'tasg_abo_nonce' );
		echo '<input type="hidden" name="tasg_abo_key" value="' . esc_attr( $key ) . '">';
		$this->type_fields();

		foreach ( $subs as $sub ) {
			echo '<div style="margin:1em 0;padding:1em;border:1px solid #ddd"><p><strong>' . esc_html( $this->title( $sub ) ) . '</strong><br>' . esc_html( $this->contract_line( $sub ) ) . '</p>';

			if ( ! empty( $sub->cancel_after ) ) {
				/* translators: %s: date the contract ends */
				echo '<p>' . esc_html( sprintf( __( 'Your cancellation was received. The contract ends on %s.', 'tasg-abo' ), $this->date( $sub->cancel_after ) ) ) . '</p>';
			} else {
				echo '<p>' . esc_html( $this->effect_text( $sub ) ) . '</p>';
				echo '<button type="submit" class="button" name="tasg_abo_cancel_go" value="' . (int) $sub->id . '">' . esc_html__( 'Cancel contract now', 'tasg-abo' ) . '</button>';
			}

			echo '</div>';
		}

		echo '<p>' . esc_html__( 'The confirmation is sent by email to the address of the contract.', 'tasg-abo' ) . '</p></form>';
	}

	/**
	 * Order number plus the questions chosen in the settings, for customers who are not logged in.
	 */
	private function guest_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$answers = isset( $_POST['cancel_answer'] ) && is_array( $_POST['cancel_answer'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['cancel_answer'] ) ) : array();

		echo '<form method="post" action="" class="tasg-abo-cancel">';
		wp_nonce_field( 'tasg_abo_cancel', 'tasg_abo_nonce' );
		echo '<p>' . esc_html__( 'To cancel without logging in, enter your order number and the details below. You find the order number in your order confirmation email.', 'tasg-abo' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Order number', 'tasg-abo' ) . '<br><input type="text" name="order_no" class="input-text" required value="' . esc_attr( $this->posted( 'order_no' ) ) . '"></label></p>';

		foreach ( Settings::cancel_fields() as $field ) {
			$label = Settings::cancel_field_choices()[ $field ];
			$type  = 'email' === $field ? 'email' : 'text';
			echo '<p><label>' . esc_html( $label ) . '<br><input type="' . esc_attr( $type ) . '" name="cancel_answer[' . esc_attr( $field ) . ']" class="input-text" required autocomplete="off" value="' . esc_attr( $answers[ $field ] ?? '' ) . '"></label></p>';
		}

		$this->type_fields();
		echo '<p><button type="submit" class="button" name="tasg_abo_cancel_go" value="guest">' . esc_html__( 'Cancel contract now', 'tasg-abo' ) . '</button></p>';
		echo '<p>' . esc_html__( 'The confirmation is sent by email to the address of the contract.', 'tasg-abo' ) . '</p></form>';
	}
}
