<?php
/**
 * PayPal at checkout for subscription carts.
 *
 * The customer is sent to PayPal, pays the first order and approves later
 * charges in the same step (PayPal Vault, saved during purchase). Only an
 * opaque vault id is kept, as order meta, nothing else about the payment method.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Checkout\CheckoutRules;
use TasgAbo\Log;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

class PayPalGateway extends \WC_Payment_Gateway {

	public const ID = 'tasg_abo_paypal';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayPal (subscriptions)', 'tasg-abo' );
		$this->method_description = __( 'PayPal for subscription orders. Set up under WooCommerce > Settings > Subscriptions.', 'tasg-abo' );
		$this->title              = __( 'PayPal', 'tasg-abo' );
		$this->description        = __( 'You pay with PayPal now and allow the next payments of your subscription. You can cancel at any time.', 'tasg-abo' );
		$this->has_fields         = false;
		$this->enabled            = 'yes';
	}

	/**
	 * Offered only for carts with a subscription, and only when set up.
	 */
	public function is_available() {
		return Settings::paypal_enabled() && Settings::paypal_configured() && CheckoutRules::cart_has_subscription();
	}
}
