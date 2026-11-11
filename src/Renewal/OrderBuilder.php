<?php
/**
 * Builds a standard WooCommerce renewal order for a subscription.
 *
 * It is a normal order (same hooks, stock handling, taxes and emails as any
 * other), created as "pending". The address always comes from the customer
 * account. Shipping method and cost are copied from the last order of the
 * subscription (never the first), or set to free during a free shipping period.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Renewal;

use TasgAbo\Pricing;

defined( 'ABSPATH' ) || exit;

final class OrderBuilder {

	public function build( object $sub ): ?\WC_Order {
		// Last order of this subscription: the previous renewal, or the first order.
		$origin  = wc_get_order( (int) $sub->last_order_id ) ?: wc_get_order( (int) $sub->origin_order_id );
		$product = wc_get_product( (int) $sub->product_id );

		if ( ! $origin || ! $product ) {
			return null;
		}

		$order = wc_create_order(
			array(
				'customer_id' => (int) $sub->user_id,
				'created_via' => 'tasg_abo',
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) ) {
			return null;
		}

		$order->set_address( $this->address( $origin, 'billing', (int) $sub->user_id ), 'billing' );
		$order->set_address( $this->address( $origin, 'shipping', (int) $sub->user_id ), 'shipping' );

		$qty = max( 1, (int) $sub->quantity );
		$net = wc_get_price_excluding_tax(
			$product,
			array(
				'qty'   => $qty,
				'price' => Pricing::subscriber_price( $product ),
			)
		);

		$order->add_product( $product, $qty, array( 'subtotal' => $net, 'total' => $net ) );

		$this->add_shipping( $order, $origin, $sub );

		$order->set_payment_method( $origin->get_payment_method() );
		$order->set_payment_method_title( $origin->get_payment_method_title() );

		$order->update_meta_data( '_tasg_abo_renewal_of', (int) $sub->id );
		$order->update_meta_data( 'wpml_language', $sub->language );
		$order->add_order_note(
			/* translators: %d: subscription id */
			sprintf( __( 'Renewal of subscription #%d.', 'tasg-abo' ), (int) $sub->id )
		);

		$order->calculate_totals( true );
		$order->save();

		return $order;
	}

	/**
	 * Address from the customer account. Only if the account has none, the last order is used.
	 */
	private function address( \WC_Order $origin, string $type, int $user_id ): array {
		$customer = new \WC_Customer( $user_id );
		$address  = 'billing' === $type ? $customer->get_billing() : $customer->get_shipping();

		if ( empty( $address['address_1'] ) ) {
			return $origin->get_address( $type );
		}

		return $address;
	}

	private function add_shipping( \WC_Order $order, \WC_Order $origin, object $sub ): void {
		$free = 'free' === $sub->shipping_mode
			&& ( empty( $sub->shipping_until ) || strtotime( $sub->shipping_until . ' UTC' ) > time() );

		if ( $free ) {
			$item = new \WC_Order_Item_Shipping();
			$item->set_method_title( __( 'Free shipping', 'tasg-abo' ) );
			$item->set_method_id( 'free_shipping' );
			$item->set_total( 0 );
			$order->add_item( $item );
			return;
		}

		foreach ( $origin->get_items( 'shipping' ) as $source ) {
			$item = new \WC_Order_Item_Shipping();
			$item->set_method_title( $source->get_method_title() );
			$item->set_method_id( $source->get_method_id() );
			$item->set_instance_id( $source->get_instance_id() );
			$item->set_total( $source->get_total() );

			// Keeps extra data of shipping plugins (for example the DHL product).
			foreach ( $source->get_meta_data() as $meta ) {
				$item->add_meta_data( $meta->key, $meta->value, true );
			}

			$order->add_item( $item );
		}
	}
}
