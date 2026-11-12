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

	/** Own text for the cancel button, empty for the default. */
	private $submit_label = '';

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Use another text on the cancel button (the Elementor widget has a field for it).
	 */
	public function with_submit_label( string $label ): self {
		$this->submit_label = $label;

		return $this;
	}

	private function submit_text(): string {
		return '' !== $this->submit_label ? $this->submit_label : __( 'Cancel contract now', 'tasg-abo' );
	}

	/**
	 * Basic look. :where() keeps it at zero weight, so any theme or Elementor style wins.
	 */
	private function default_css(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;
		echo '<style>:where(.tasg-abo-cancel-wrap) .tasg-abo-contract{margin:1em 0;padding:1em;border:1px solid #ddd}:where(.tasg-abo-cancel-wrap) .tasg-abo-input{width:100%;box-sizing:border-box}</style>';
	}

	/**
	 * What the form looks like with sample data, for the Elementor editor.
	 */
	public function preview(): string {
		ob_start();
		$this->default_css();
		echo '<div class="tasg-abo-cancel-wrap">';
		$this->guest_form();
		$this->notice( __( 'We could not find an open subscription with these details.', 'tasg-abo' ) );
		echo '<div class="tasg-abo-contract"><p><strong>' . esc_html__( 'Product', 'tasg-abo' ) . ' (' . esc_html__( 'Monthly', 'tasg-abo' ) . ')</strong><br>' . esc_html__( 'Subscription no. 12, first order no. 1234', 'tasg-abo' ) . '</p><p>' . esc_html__( 'If you cancel now, the contract ends today and you will not be charged again.', 'tasg-abo' ) . '</p>';
		echo '<button type="button" class="button tasg-abo-submit">' . esc_html( $this->submit_text() ) . '</button></div></div>';

		return (string) ob_get_clean();
	}

	public function render(): string {
		ob_start();
		$this->default_css();
		echo '<div class="tasg-abo-cancel-wrap">';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['tasg_abo_cancel_go'] ) ) {
			$this->handle_post();
		} else {
			$this->show_form();
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	private function is_open( object $sub ): bool {
		return in_array( $sub->status, array( 'active', 'paused' ), true );
	}

	private function notice( string $text, bool $error = true ): void {
		echo '<div class="tasg-abo-notice ' . ( $error ? 'tasg-abo-notice--error woocommerce-error' : 'tasg-abo-notice--info woocommerce-info' ) . '" role="alert">' . esc_html( $text ) . '</div>';
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

		echo '<p><strong class="tasg-abo-label">' . esc_html__( 'Type of cancellation', 'tasg-abo' ) . '</strong><br>';
		echo '<label><input type="radio" name="cancel_type" value="ordinary"' . checked( $type, 'ordinary', false ) . '> ' . esc_html__( 'Ordinary cancellation', 'tasg-abo' ) . '</label><br>';
		echo '<label><input type="radio" name="cancel_type" value="extraordinary"' . checked( $type, 'extraordinary', false ) . '> ' . esc_html__( 'Extraordinary cancellation (please give the reason)', 'tasg-abo' ) . '</label></p>';
		echo '<p><label class="tasg-abo-label">' . esc_html__( 'Reason (only for an extraordinary cancellation)', 'tasg-abo' ) . '<br>';
		echo '<textarea name="cancel_reason" rows="3" class="input-text tasg-abo-input">' . esc_textarea( $this->posted( 'cancel_reason' ) ) . '</textarea></label></p>';
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
			echo '<div class="tasg-abo-contract"><p><strong>' . esc_html( $this->title( $sub ) ) . '</strong><br>' . esc_html( $this->contract_line( $sub ) ) . '</p>';

			if ( ! empty( $sub->cancel_after ) ) {
				/* translators: %s: date the contract ends */
				echo '<p>' . esc_html( sprintf( __( 'Your cancellation was received. The contract ends on %s.', 'tasg-abo' ), $this->date( $sub->cancel_after ) ) ) . '</p>';
			} else {
				echo '<p>' . esc_html( $this->effect_text( $sub ) ) . '</p>';
				echo '<button type="submit" class="button tasg-abo-submit" name="tasg_abo_cancel_go" value="' . (int) $sub->id . '">' . esc_html( $this->submit_text() ) . '</button>';
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
		echo '<p><label class="tasg-abo-label">' . esc_html__( 'Order number', 'tasg-abo' ) . '<br><input type="text" name="order_no" class="input-text tasg-abo-input" required value="' . esc_attr( $this->posted( 'order_no' ) ) . '"></label></p>';

		foreach ( Settings::cancel_fields() as $field ) {
			$label = Settings::cancel_field_choices()[ $field ];
			$type  = 'email' === $field ? 'email' : 'text';
			echo '<p><label class="tasg-abo-label">' . esc_html( $label ) . '<br><input type="' . esc_attr( $type ) . '" name="cancel_answer[' . esc_attr( $field ) . ']" class="input-text tasg-abo-input" required autocomplete="off" value="' . esc_attr( $answers[ $field ] ?? '' ) . '"></label></p>';
		}

		$this->type_fields();
		echo '<p><button type="submit" class="button tasg-abo-submit" name="tasg_abo_cancel_go" value="guest">' . esc_html( $this->submit_text() ) . '</button></p>';
		echo '<p>' . esc_html__( 'The confirmation is sent by email to the address of the contract.', 'tasg-abo' ) . '</p></form>';
	}

	private function handle_post(): void {
		$key = $this->posted( 'tasg_abo_key' );

		if ( ! wp_verify_nonce( $this->posted( 'tasg_abo_nonce' ), 'tasg_abo_cancel' ) ) {
			$this->notice( __( 'The page has expired. Please try again.', 'tasg-abo' ) );
			$this->show_form( $key );
			return;
		}

		$type   = 'extraordinary' === $this->posted( 'cancel_type' ) ? 'extraordinary' : 'ordinary';
		$reason = mb_substr( $this->posted( 'cancel_reason' ), 0, 500 );

		if ( 'extraordinary' === $type && '' === trim( $reason ) ) {
			$this->notice( __( 'Please give the reason of an extraordinary cancellation.', 'tasg-abo' ) );
			$this->show_form( $key );
			return;
		}

		if ( 'guest' === $this->posted( 'tasg_abo_cancel_go' ) && Verifier::blocked() ) {
			$this->notice( __( 'Too many attempts. Please try again in a few minutes.', 'tasg-abo' ) );
			return;
		}

		$sub = $this->posted_subscription( $key );

		if ( ! $sub ) {
			$this->notice( __( 'We could not find an open subscription with these details.', 'tasg-abo' ) );
			$this->show_form( $key );
			return;
		}

		$this->cancel( $sub, $type, $reason );
	}

	/**
	 * The subscription the visitor is allowed to cancel with this request, or null.
	 */
	private function posted_subscription( string $key ): ?object {
		$go = $this->posted( 'tasg_abo_cancel_go' );

		if ( 'guest' === $go ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$answers = isset( $_POST['cancel_answer'] ) && is_array( $_POST['cancel_answer'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['cancel_answer'] ) ) : array();

			return ( new Verifier( $this->repository ) )->find( $this->posted( 'order_no' ), $answers );
		}

		$sub = $this->repository->find( (int) $go );

		if ( ! $sub || ! $this->is_open( $sub ) ) {
			return null;
		}

		if ( '' !== $key && hash_equals( (string) $sub->cancel_key, $key ) ) {
			return $sub;
		}

		return is_user_logged_in() && (int) $sub->user_id === get_current_user_id() ? $sub : null;
	}

	private function cancel( object $sub, string $type, string $reason ): void {
		if ( ! empty( $sub->cancel_after ) || ! ( new Actions( $this->repository ) )->request_cancel( $sub ) ) {
			$this->notice( __( 'This contract was already cancelled.', 'tasg-abo' ), false );
			return;
		}

		$row = $this->repository->find( (int) $sub->id );
		$row = $row ? $row : $sub;
		Log::info( sprintf( 'Subscription #%d: cancelled by the customer on the cancel page (%s).', (int) $row->id, $type ) );

		// The owner decides about an extraordinary cancellation, so the reason goes on the order.
		$order = wc_get_order( (int) $row->origin_order_id );
		if ( $order ) {
			/* translators: 1: type of cancellation, 2: reason */
			$order->add_order_note( sprintf( __( 'Customer cancellation on the cancel page. Type: %1$s. Reason: %2$s', 'tasg-abo' ), $type, '' !== $reason ? $reason : '-' ) );
		}

		CancelConfirmationEmail::$details = array(
			'type'   => $type,
			'reason' => $reason,
		);

		$emails = WC()->mailer()->get_emails();
		$sent   = isset( $emails['tasg_abo_cancel_confirmation'] ) && $emails['tasg_abo_cancel_confirmation']->trigger( (int) $row->id );
		Log::info( sprintf( 'Subscription #%d: cancellation confirmation %s.', (int) $row->id, $sent ? 'sent' : 'NOT sent' ) );

		$this->result( $row, $sent );
	}

	/**
	 * What was cancelled and when, for the customer to keep.
	 */
	private function result( object $row, bool $sent ): void {
		echo '<h3>' . esc_html__( 'Your cancellation was received', 'tasg-abo' ) . '</h3><ul>';
		echo '<li>' . esc_html( $this->title( $row ) . ', ' . $this->contract_line( $row ) ) . '</li>';
		/* translators: %s: date and time */
		echo '<li>' . esc_html( sprintf( __( 'Received: %s', 'tasg-abo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ) . '</li>';
		/* translators: %s: date */
		echo '<li>' . esc_html( sprintf( __( 'The contract ends: %s', 'tasg-abo' ), wp_date( get_option( 'date_format' ), Actions::end_timestamp( $row ) ) ) ) . '</li></ul>';
		echo '<p>' . esc_html( $sent ? __( 'We sent a confirmation to your email address.', 'tasg-abo' ) : __( 'We could not send the confirmation by email. Please save or print this page as your proof.', 'tasg-abo' ) ) . '</p>';
	}
}
