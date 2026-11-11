<?php
/**
 * Creates subscriptions from a paid first order.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Log;
use TasgAbo\Plans\Period;

defined( 'ABSPATH' ) || exit;

final class Creator {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_create' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_create' ) );
	}

	public function maybe_create( $order_id ): void {
		$order = wc_get_order( $order_id );

		// Renewal orders made by this plugin never start another subscription.
		if ( ! $order || $order->get_meta( '_tasg_abo_created' ) || $order->get_meta( '_tasg_abo_renewal_of' ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$made    = 0;

		// PayPal can only be charged again if the customer saved the payment method.
		$gateway = (string) $order->get_payment_method();
		if ( in_array( $gateway, \TasgAbo\Gateways\PayPalCharger::GATEWAY_IDS, true ) && ! \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway ) ) {
			$order->add_order_note( __( 'No subscription created: the customer did not save the PayPal payment method.', 'tasg-abo' ) );
			return;
		}

		// Stripe can only charge again if the order carries the references to the saved payment
		// method. Without them (a method that cannot be reused, or a guest payment) the renewal
		// would fail later, so it is said now instead.
		if ( 0 === strpos( $gateway, 'stripe' ) && ( '' === (string) $order->get_meta( '_stripe_customer_id' ) || '' === (string) $order->get_meta( '_stripe_source_id' ) ) ) {
			$order->add_order_note( __( 'No subscription created: the payment method cannot be charged again.', 'tasg-abo' ) );
			\TasgAbo\Log::warning( sprintf( 'Order #%d: no subscription created, the Stripe payment method is not saved for reuse.', $order->get_id() ) );
			return;
		}

		foreach ( $order->get_items() as $item ) {
			// Copy of the plan as it was when the customer ordered.
			$plan = json_decode( (string) $item->get_meta( '_tasg_abo_plan' ), true );

			if ( ! is_array( $plan ) || (int) ( $plan['value'] ?? 0 ) < 1 || $user_id < 1 ) {
				continue;
			}

			$value = (int) $plan['value'];
			$unit  = in_array( $plan['unit'] ?? '', Period::UNITS, true ) ? $plan['unit'] : 'week';

			$new_id = $this->repository->insert(
				array(
					'user_id'          => $user_id,
					'product_id'       => (int) $item->get_product_id(),
					'quantity'         => (int) $item->get_quantity(),
					'is_test'          => 'yes' === (string) $item->get_meta( '_tasg_abo_test' ) ? 1 : 0,
					'plan_id'          => (int) ( $plan['id'] ?? 0 ),
					'plan_name'        => (string) ( $plan['name'] ?? '' ),
					'period_value'     => $value,
					'period_unit'      => $unit,
					'reminder_minutes' => max( 1, (int) ( $plan['reminder_minutes'] ?? 2880 ) ),
					'status'           => 'active',
					'next_payment'     => gmdate( 'Y-m-d H:i:s', Period::add( time(), $value, $unit ) ),
					'origin_order_id'  => $order->get_id(),
					'gateway'          => (string) $order->get_payment_method(),
					'last_order_id'    => $order->get_id(),
					'language'         => $this->order_language( $order, $item ),
				)
			);
			++$made;

			if ( 'yes' === (string) $item->get_meta( '_tasg_abo_test' ) ) {
				\TasgAbo\TestMarker::apply( $order );
			}
			Log::info( sprintf( 'Subscription #%d created from order #%d (plan "%s", every %d %s).', $new_id, $order->get_id(), (string) ( $plan['name'] ?? '' ), $value, $unit ) );
		}

		if ( $made > 0 ) {
			$order->update_meta_data( '_tasg_abo_created', 'yes' );
			$order->add_order_note(
				/* translators: %d: number of subscriptions */
				sprintf( _n( '%d subscription created.', '%d subscriptions created.', $made, 'tasg-abo' ), $made )
			);
			$order->save();
		}
	}

	/**
	 * Language of the order as stored by WPML, or the site locale as fallback.
	 */
	private function order_language( \WC_Order $order, \WC_Order_Item $item ): string {
		// Taken at checkout in the customer's language, then the order's own WPML meta.
		$lang = (string) $item->get_meta( '_tasg_abo_lang' );

		if ( '' === $lang ) {
			$lang = (string) $order->get_meta( 'wpml_language' );
		}

		return '' !== $lang ? $lang : (string) determine_locale();
	}
}
