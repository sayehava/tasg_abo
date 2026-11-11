<?php
/**
 * Warns a customer that the saved card expires before the next order.
 *
 * Placeholders in addition to the common ones: {card_name} {card_expiry}
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Gateways\CardCheck;

defined( 'ABSPATH' ) || exit;

final class CardExpiringEmail extends SubscriptionEmail {

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_card_expiring',
			__( 'Card expires before next order', 'tasg-abo' ),
			__( 'Sent when the saved card of a subscription expires before the next order, so the customer can choose another payment method in time.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your payment method expires before your next order of {product_name}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your card is about to expire', 'tasg-abo' );
	}

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nthe payment method {card_name} for your subscription of {product_name} expires on {card_expiry}, before your next order on {next_date}. Please choose another payment method so that your order can be charged:\n{payment_url}\n\nIf the payment fails, the subscription is cancelled.",
			'tasg-abo'
		);
	}

	protected function extra_placeholders( object $row ): array {
		// The test email in the editor uses made up values.
		if ( 0 === (int) $row->id ) {
			return array(
				'{card_name}'   => 'Visa **** 4242',
				'{card_expiry}' => '12/' . gmdate( 'Y' ),
			);
		}

		$info = CardCheck::info( $row );

		return array(
			'{card_name}'   => $info ? $info['name'] : '',
			'{card_expiry}' => $info ? $info['label'] : '',
		);
	}
}
