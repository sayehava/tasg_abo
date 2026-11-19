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

	/**
	 * Paid sandbox orders get the Sandbox status instead of Processing or Completed.
	 *
	 * @param string         $status   Status WooCommerce would set.
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order.
	 */
	public function paid_status( $status, $order_id = 0, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		return self::is_order( $order ) ? self::STATUS : $status;
	}

	public function no_stock( $can, $order = null ) {
		return self::is_order( $order ) ? false : $can;
	}

	public function no_invoice( $needs, $order = null ) {
		return self::is_order( $order ) ? false : $needs;
	}

	public function no_stock_by_id( $reduce, $order_id = 0 ) {
		return self::is_order( wc_get_order( $order_id ) ) ? false : $reduce;
	}

	/**
	 * The paid emails of WooCommerce go out on Processing or Completed, so for the
	 * Sandbox status they are sent here: the customer's order email and the shop's
	 * new order email (WooCommerce sends that one only once per order).
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order.
	 */
	public function send_paid_emails( $order_id, $order = null ): void {
		$emails = WC()->mailer()->get_emails();

		foreach ( array( 'WC_Email_Customer_Processing_Order', 'WC_Email_New_Order' ) as $class ) {
			if ( isset( $emails[ $class ] ) ) {
				$emails[ $class ]->trigger( (int) $order_id, $order instanceof \WC_Order ? $order : false );
			}
		}
	}

	/**
	 * Every email about a sandbox order or a sandbox subscription gets SANDBOX in front
	 * of its subject, our subscription emails as well as the WooCommerce ones.
	 *
	 * @param \WC_Emails $mailer WooCommerce mailer.
	 */
	public function mark_subjects( $mailer ): void {
		foreach ( $mailer->get_emails() as $email ) {
			add_filter( 'woocommerce_email_subject_' . $email->id, array( $this, 'subject' ), 99, 2 );
		}
	}

	public function subject( $subject, $object = null ) {
		$order = $object;

		// Our subscription emails carry the subscription row: its first order decides.
		if ( is_object( $object ) && isset( $object->origin_order_id ) ) {
			$order = wc_get_order( (int) $object->origin_order_id );
		}

		return self::is_order( $order ) && 0 !== strpos( (string) $subject, '[SANDBOX]' ) ? '[SANDBOX] ' . $subject : $subject;
	}
}
