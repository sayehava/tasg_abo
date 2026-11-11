<?php
/**
 * Asks a paused customer to continue or cancel. Sent up to three times.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

defined( 'ABSPATH' ) || exit;

final class PauseReminderEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_pause_reminder',
			__( 'Subscription pause reminder', 'tasg-abo' ),
			__( 'Sent to a customer whose pause is over, asking them to continue or cancel. After the third reminder without an answer the subscription is cancelled.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Do you want to continue your subscription for {product_name}?', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your subscription is paused', 'tasg-abo' );
	}

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nyour subscription for {product_name} is paused. Please tell us what you want to do. If we do not hear from you, the subscription will be cancelled after a few more reminders.\n\nContinue: {continue_url}\nCancel: {cancel_url}",
			'tasg-abo'
		);
	}
}
