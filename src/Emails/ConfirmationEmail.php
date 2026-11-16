<?php
/**
 * Sent to the customer when a subscription starts: what was subscribed, the plan,
 * the date of the next order and how to manage the subscription.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class ConfirmationEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_confirmation',
			__( 'Subscription confirmation', 'tasg-abo' ),
			__( 'Sent to the customer when a subscription starts: product, plan, date of the next order and how to manage the subscription.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your subscription to {product_name}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Thank you for your subscription', 'tasg-abo' );
	}

	public static function default_body(): string {
		$text = __(
			"Hello {customer_name},\n\nthank you for subscribing to {product_name}. Your subscription number is {subscription_id}.\n\nPlan: {plan_name}\nAmount per order: {amount} plus shipping\nYour next order: {next_date}\n\nYour orders will be sent to:\n{shipping_address}\n\n{term_note}",
			'tasg-abo'
		);

		$text .= "\n\n" . __( 'We will remind you by email before each order. You can manage your subscription at any time:', 'tasg-abo' );

		$lines = array();

		if ( Settings::customer_may( 'skip' ) ) {
			$lines[] = __( 'Skip this order: {skip_url}', 'tasg-abo' );
		}
		if ( Settings::customer_may( 'pause' ) ) {
			$lines[] = __( 'Pause: {pause_url}', 'tasg-abo' );
		}
		if ( Settings::customer_may( 'payment' ) ) {
			$lines[] = __( 'Change your payment method: {payment_url}', 'tasg-abo' );
		}

		$lines[] = __( 'Cancel your subscription: {cancel_url}', 'tasg-abo' );

		return $text . "\n" . implode( "\n", $lines );
	}

	protected function extra_placeholders( object $row ): array {
		return array(
			'{subscription_id}' => (string) (int) $row->id,
			'{plan_name}'       => (string) $row->plan_name,
		);
	}
}
