<?php
/**
 * Orders paid in the Stripe sandbox. They run like real orders (subscription, emails,
 * renewals), but get their own status "Sandbox" instead of Processing or Completed:
 * not in the normal order list, not in analytics, no stock taken, no invoice or label
 * automation, and every email says SANDBOX in the subject.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Sandbox;

use TasgAbo\Gateways\StripeCharger;
use TasgAbo\Log;

defined( 'ABSPATH' ) || exit;

final class SandboxOrders {

	public const STATUS = 'tasg-sandbox';
	public const META   = '_tasg_abo_sandbox';

	public function register(): void {
		add_action( 'init', array( $this, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_status' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'add_paid_status' ) );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', array( $this, 'add_paid_status' ) );
		// Germanized Pro (StoreaBill) creates invoices as soon as an order is paid: never for sandbox orders.
		add_filter( 'storeabill_woo_order_needs_billing', array( $this, 'no_invoice' ), 99, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'mark' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'paid_status' ), 99, 3 );
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'no_stock' ), 99, 2 );
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', array( $this, 'no_stock_by_id' ), 99, 2 );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( $this, 'send_paid_emails' ), 20, 2 );
		add_action( 'woocommerce_email', array( $this, 'mark_subjects' ) );
	}

	public function register_status(): void {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Sandbox', 'Order status', 'tasg-abo' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Sandbox <span class="count">(%s)</span>', 'Sandbox <span class="count">(%s)</span>', 'tasg-abo' ),
			)
		);
	}

	public function add_status( $statuses ) {
		$statuses[ 'wc-' . self::STATUS ] = _x( 'Sandbox', 'Order status', 'tasg-abo' );
		return $statuses;
	}

	public function add_paid_status( $statuses ) {
		$statuses   = (array) $statuses;
		$statuses[] = self::STATUS;
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Note on every new order: sandbox or not, and for subscriptions the Stripe mode, so
	 * renewals use the same keys (Adaptive Pricing payments pass no other hook).
	 *
	 * @param \WC_Order $order Order just created at checkout.
	 */
	public function mark( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$sandbox = StripeSandbox::on_for_user();

		if ( $sandbox ) {
			$order->update_meta_data( self::META, 'yes' );
			$order->add_order_note( __( 'Sandbox order: paid with Stripe test keys, not a real order. No stock, no invoice, no shipping.', 'tasg-abo' ) );
			Log::info( sprintf( 'Order #%d is a sandbox order.', $order->get_id() ) );
		}

		if ( 0 === strpos( (string) $order->get_payment_method(), 'stripe' ) && \TasgAbo\Checkout\CheckoutRules::order_has_subscription( $order ) && class_exists( 'WC_Stripe_Mode' ) ) {
			$order->update_meta_data( StripeCharger::MODE_META, \WC_Stripe_Mode::is_test() ? 'test' : 'live' );
		}

		$order->save();
	}

	/** Sandbox order, or a renewal of a subscription that started as one. */
	public static function is_order( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		return 'yes' === $order->get_meta( self::META );
	}

}
