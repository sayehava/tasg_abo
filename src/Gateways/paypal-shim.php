<?php
/**
 * Compatibility shim for the PayPal renewal code.
 *
 * The WooCommerce PayPal Payments plugin asks WooCommerce Subscriptions which
 * subscription a renewal order belongs to. This plugin does not use that
 * extension, so the answer is always "none". Declared in the global namespace,
 * and only when WooCommerce Subscriptions is not installed.
 *
 * @package TasgAbo
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
	/**
	 * @param mixed $order Renewal order.
	 * @return array
	 */
	function wcs_get_subscriptions_for_renewal_order( $order ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		return array();
	}
}
