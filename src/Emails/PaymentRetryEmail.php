<?php
/**
 * Sent when a renewal payment was declined and will be tried again. The
 * subscription goes on; the customer can check or change the payment method.
 *
 * Extra placeholder: {retry_date} (date and time of the next try).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Renewal\DeclineRetry;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class PaymentRetryEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_payment_retry',
			__( 'Subscription payment will be retried', 'tasg-abo' ),
			__( 'Sent when a renewal payment was declined. The subscription continues and the payment is tried again 1, 3 and 5 days after it was due.', 'tasg-abo' )
		);

		$this->placeholders['{retry_date}'] = '';

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your payment for {product_name} did not go through', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Please check your payment method', 'tasg-abo' );
	}

	public static function default_body(): string {
		$text = __(
			"Hello {customer_name},\n\nwe could not charge your payment method for your next order of {product_name} ({amount}). Your subscription continues and we will try again on {retry_date}.",
			'tasg-abo'
		);

		if ( Settings::customer_may( 'payment' ) ) {
			$text .= "\n\n" . __( 'Please check your payment method or choose another one, then we try again at once: {payment_url}', 'tasg-abo' );
		}

		$text .= "\n\n" . __( 'If the last try also fails, your subscription ends.', 'tasg-abo' );

		return $text;
	}

	protected function extra_placeholders( object $row ): array {
		$at = DeclineRetry::get( (int) $row->id )['retry_at'];

		return array(
			'{retry_date}' => $at > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) : '',
		);
	}
}
