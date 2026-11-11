<?php
/**
 * Creates subscriptions from a paid first order.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

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

		foreach ( $order->get_items() as $item ) {
			$weeks = (int) $item->get_meta( '_tasg_abo_interval' );

			if ( $weeks < 1 || $user_id < 1 ) {
				continue;
			}

			$this->repository->insert(
				array(
					'user_id'          => $user_id,
					'product_id'       => (int) $item->get_product_id(),
					'quantity'         => (int) $item->get_quantity(),
					'interval_weeks'   => $weeks,
					'status'           => 'active',
					'next_payment'     => gmdate( 'Y-m-d H:i:s', time() + $weeks * WEEK_IN_SECONDS ),
					'origin_order_id'  => $order->get_id(),
					'gateway'          => (string) $order->get_payment_method(),
					'last_order_id'    => $order->get_id(),
					'language'         => $this->order_language( $order ),
				)
			);
			++$made;
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
	private function order_language( \WC_Order $order ): string {
		$lang = (string) $order->get_meta( 'wpml_language' );

		return '' !== $lang ? $lang : (string) determine_locale();
	}
}
