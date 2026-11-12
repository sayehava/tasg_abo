<?php
/**
 * "We will charge you again" email, sent before a renewal. The lead time is set per plan.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class ReminderEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_reminder',
			__( 'Subscription reminder', 'tasg-abo' ),
			__( 'Sent to the customer before the next subscription order is charged. How long before is set per plan.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your next order of {product_name} on {next_date}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your next subscription order', 'tasg-abo' );
	}

	/**
	 * The text lists only what the shop lets customers do, so no link leads to a switched off option.
	 */
	public static function default_body(): string {
		$text = __(
			"Hello {customer_name},\n\nwe will charge you again for your next order of {product_name} on {next_date}. Amount: {amount} plus shipping.\n\nYour order will be sent to:\n{shipping_address}",
			'tasg-abo'
		);

		if ( Settings::customer_may( 'address' ) ) {
			$text .= "\n\n" . __( 'If this address is not correct, please change it before {next_date}: {address_url}', 'tasg-abo' );
		}

		$text .= "\n\n{term_note}";
		$text .= "\n\n" . __( 'You do not need to do anything else to continue.', 'tasg-abo' );

		$lines = array();

		if ( Settings::customer_may( 'skip' ) ) {
			$lines[] = __( 'Skip this order: {skip_url}', 'tasg-abo' );
		}
		if ( Settings::customer_may( 'pause' ) ) {
			$lines[] = __( 'Pause: {pause_url}', 'tasg-abo' );
		}
		if ( Settings::customer_may( 'plan' ) ) {
			$lines[] = __( 'Change your plan: {plan_url}', 'tasg-abo' );
		}
		if ( Settings::customer_may( 'payment' ) ) {
			$lines[] = __( 'Change your payment method: {payment_url}', 'tasg-abo' );
		}

		$lines[] = __( 'Cancel your subscription: {cancel_url}', 'tasg-abo' );

		return $text . "\n\n" . implode( "\n", $lines );
	}
}
