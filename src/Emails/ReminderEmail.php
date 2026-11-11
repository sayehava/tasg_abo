<?php
/**
 * "We will charge you again" email, sent before a renewal. The lead time is set per plan.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

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

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nwe will charge you again for your next order of {product_name} on {next_date}. Amount: {amount} plus shipping.\n\nYour order will be sent to:\n{shipping_address}\n\nIf this address is not correct, please change it before {next_date}: {address_url}\n\nYou do not need to do anything else to continue.\n\nSkip this order: {skip_url}\nPause: {pause_url}\nCancel your subscription: {cancel_url}",
			'tasg-abo'
		);
	}
}
