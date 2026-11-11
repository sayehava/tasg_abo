<?php
/**
 * The Stripe payment methods the shop has switched on, which of them can be charged
 * again later, and which of them the shop owner allows for subscriptions.
 *
 * Everything is read from the WooCommerce Stripe Gateway plugin. Nothing is stored
 * here except the owner's choice (Settings::STRIPE_METHODS).
 *
 * @package TasgAbo
 */

namespace TasgAbo\Gateways;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class StripeMethods {

	/**
	 * Reusable types as flagged by the Stripe plugin, used only if the plugin cannot be asked.
	 */
	private const FALLBACK_REUSABLE = array( 'card', 'link', 'klarna', 'amazon_pay', 'sepa_debit', 'bacs_debit', 'au_becs_debit', 'us_bank_account', 'acss_debit', 'cashapp', 'sofort' );

	/**
	 * Types Stripe's payment form cannot exclude (the form would fail), so they cannot be limited per cart.
	 */
	private const FALLBACK_NON_EXCLUDABLE = array( 'apple_pay', 'google_pay', 'link', 'cartes_bancaires', 'jcb' );

	private static function gateway() {
		if ( ! class_exists( '\WC_Stripe' ) ) {
			return null;
		}

		try {
			return \WC_Stripe::get_instance()->get_main_stripe_gateway();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Stripe types switched on in the shop, for example card, link, klarna.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		$gateway = self::gateway();

		if ( ! $gateway || ! method_exists( $gateway, 'get_upe_enabled_payment_method_ids' ) ) {
			return array();
		}

		try {
			return array_values( array_map( 'strval', (array) $gateway->get_upe_enabled_payment_method_ids() ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private static function method_object( string $id ) {
		$gateway = self::gateway();

		if ( ! $gateway || ! method_exists( $gateway, 'get_upe_available_payment_methods' ) ) {
			return null;
		}

		try {
			$methods = (array) $gateway->get_upe_available_payment_methods();
		} catch ( \Throwable $e ) {
			return null;
		}

		return isset( $methods[ $id ] ) && is_object( $methods[ $id ] ) ? $methods[ $id ] : null;
	}

	public static function is_reusable( string $id ): bool {
		$method = self::method_object( $id );

		if ( $method && method_exists( $method, 'is_reusable' ) ) {
			return (bool) $method->is_reusable();
		}

		return in_array( $id, self::FALLBACK_REUSABLE, true );
	}

	public static function label( string $id ): string {
		$method = self::method_object( $id );

		if ( $method && method_exists( $method, 'get_label' ) && '' !== (string) $method->get_label() ) {
			return (string) $method->get_label();
		}

		return ucfirst( str_replace( '_', ' ', $id ) );
	}

	/**
	 * Active in Stripe and reusable: id => label.
	 *
	 * @return array<string,string>
	 */
	public static function selectable(): array {
		$list = array();

		foreach ( self::enabled() as $id ) {
			if ( self::is_reusable( $id ) ) {
				$list[ $id ] = self::label( $id );
			}
		}

		return $list;
	}

	/**
	 * Active in Stripe but not reusable: id => label.
	 *
	 * @return array<string,string>
	 */
	public static function not_reusable(): array {
		$list = array();

		foreach ( self::enabled() as $id ) {
			if ( ! self::is_reusable( $id ) ) {
				$list[ $id ] = self::label( $id );
			}
		}

		return $list;
	}

	/**
	 * Types the owner allows for subscriptions. Never saved yet means all selectable ones.
	 *
	 * @return string[]
	 */
	public static function allowed(): array {
		$selectable = array_keys( self::selectable() );
		$saved      = get_option( Settings::STRIPE_METHODS, null );

		if ( ! is_array( $saved ) ) {
			return $selectable;
		}

		return array_values( array_intersect( $selectable, array_map( 'strval', $saved ) ) );
	}

	/**
	 * Types to keep out of Stripe's payment form for a subscription cart: everything active
	 * that is not allowed, except what the form cannot exclude.
	 *
	 * @return string[]
	 */
	public static function excluded_types(): array {
		$non_excludable = self::FALLBACK_NON_EXCLUDABLE;

		if ( class_exists( 'WC_Stripe_Payment_Methods' ) && defined( 'WC_Stripe_Payment_Methods::NON_EXCLUDABLE_PAYMENT_METHOD_TYPES' ) ) {
			$non_excludable = (array) constant( 'WC_Stripe_Payment_Methods::NON_EXCLUDABLE_PAYMENT_METHOD_TYPES' );
		}

		return array_values( array_diff( self::enabled(), self::allowed(), $non_excludable ) );
	}
}
