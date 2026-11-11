<?php
/**
 * Tells the customer a renewal could not be charged and the subscription ended.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

defined( 'ABSPATH' ) || exit;

final class FailedEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_failed',
			__( 'Subscription payment failed', 'tasg-abo' ),
			__( 'Sent when a renewal could not be charged. No order is created and the subscription is cancelled.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'We could not charge your subscription for {product_name}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your subscription was cancelled', 'tasg-abo' );
	}

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nwe could not charge your payment method for your subscription of {product_name}. No order was created and the subscription is cancelled.\n\nYou are welcome to subscribe again in our shop at any time.",
			'tasg-abo'
		);
	}
}
