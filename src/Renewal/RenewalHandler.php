<?php
/**
 * Handles the queued "tasg_abo_renew" job: creates the renewal order and
 * charges the saved payment method.
 *
 * - Success: the order is paid and gets the status chosen in the settings,
 *   exactly like a normal order (stock, emails, invoice automation).
 * - Payment declined: no order is kept, the subscription stays active, the customer
 *   is asked to check the payment method and the same order is tried again 1, 3
 *   and 5 days after it was due (DeclineRetry). Cancelled only when the last try fails.
 * - Temporary problem (network, Stripe outage, unexpected error before the
 *   charge): nothing is charged, the renewal is tried again after 1 hour, then
 *   after 2 hours; the third failure is handled like a decline.
 * - An error after the money was taken is only logged. The order stays and the
 *   renewal is never repeated, so the customer cannot be charged twice.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Renewal;

use TasgAbo\Gateways\PayPalCharger;
use TasgAbo\Gateways\StripeCharger;
use TasgAbo\Log;
use TasgAbo\Plans\Period;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class RenewalHandler {

	private const MAX_TEMPORARY_ERRORS = 3;

	private $repository;
	private $builder;
	private $stripe;
	private $paypal;

	public function __construct( Repository $repository, OrderBuilder $builder, StripeCharger $stripe, PayPalCharger $paypal ) {
		$this->repository = $repository;
		$this->builder    = $builder;
		$this->stripe     = $stripe;
		$this->paypal     = $paypal;
	}

	public function register(): void {
		add_action( 'tasg_abo_renew', array( $this, 'handle' ) );
		add_action( 'tasg_abo_subscription_cancelled', array( DeclineRetry::class, 'clear' ) );
	}

	public function handle( $subscription_id ): void {
		$row = $this->repository->find( (int) $subscription_id );

		if ( ! $row || 'active' !== $row->status || 1 !== (int) $row->reminder_sent ) {
			return;
		}

		$due = strtotime( $row->next_payment . ' UTC' );

		// A retry waits on a due date in the past. A future one (the order was skipped
		// meanwhile) means nothing is waiting, so an old count must not carry over.
		if ( $due > time() ) {
			DeclineRetry::clear( (int) $row->id );
		}

		if ( $due > time() || (int) get_transient( 'tasg_abo_retry_at_' . (int) $row->id ) > time() || DeclineRetry::waiting( (int) $row->id ) ) {
			return;
		}

		// Move the schedule on first. Only one worker can win this step.
		$next = Period::add( $due, (int) $row->period_value, (string) $row->period_unit, Period::anchor_day( $row ) );
		if ( $next <= time() + (int) $row->reminder_minutes * MINUTE_IN_SECONDS + 15 * MINUTE_IN_SECONDS ) {
			$next = Period::add( time(), (int) $row->period_value, (string) $row->period_unit, Period::anchor_day( $row ) );
		}

		if ( ! $this->repository->claim( (int) $row->id, gmdate( 'Y-m-d H:i:s', $next ) ) ) {
			return;
		}

		Log::info( sprintf( 'Subscription #%d: renewal started (due %s UTC, next %s UTC).', (int) $row->id, $row->next_payment, gmdate( 'Y-m-d H:i:s', $next ) ) );

		$charger = null;
		if ( $this->stripe->supports( (string) $row->gateway ) ) {
			$charger = $this->stripe;
		} elseif ( $this->paypal->supports( (string) $row->gateway ) ) {
			$charger = $this->paypal;
		}

		if ( ! $charger ) {
			$this->fail( $row, null, 'Payment gateway cannot be charged again: ' . $row->gateway );
			return;
		}

		$order = null;

		// Each retry after a decline is a new attempt for the provider. With the same key
		// Stripe would answer with the stored decline for 24 hours.
		$attempt     = DeclineRetry::get( (int) $row->id )['tries'];
		$idempotency = 'tasg-abo-' . (int) $row->id . '-' . md5( $row->next_payment ) . ( $attempt > 0 ? '-r' . $attempt : '' );

		// Everything up to and including the charge. Any unexpected error here means
		// nothing was taken, so the renewal goes back for another try.
		try {
			$order = $this->builder->build( $row );

			if ( ! $order ) {
				$this->fail( $row, null, 'Renewal order could not be created.' );
				return;
			}

			$result = (float) $order->get_total() > 0
				? $charger->charge( $order, $row, $idempotency )
				: array(
					'status'  => 'succeeded',
					'id'      => '',
					'charge'  => '',
					'message' => '',
				);
		} catch ( \Throwable $e ) {
			$this->retry_later( $row, $order, $e->getMessage() );
			return;
		}

		Log::info( sprintf( 'Subscription #%d: charge for order #%d returned "%s" (%s).', (int) $row->id, $order->get_id(), $result['status'], $result['id'] ) );

		switch ( $result['status'] ) {
			case 'succeeded':
				$this->clear_retry( $row );
				DeclineRetry::clear( (int) $row->id );
				$this->complete( $order, $row, $result );
				break;

			case 'processing':
				$this->clear_retry( $row );
				DeclineRetry::clear( (int) $row->id );
				$this->hold( $order, $row, $result );
				break;

			case 'error':
				$this->retry_later( $row, $order, $result['message'] );
				break;

			default:
				$this->declined( $row, $order, $result['message'] );
		}
	}

	private function clear_retry( object $sub ): void {
		delete_transient( 'tasg_abo_retry_' . (int) $sub->id );
		delete_transient( 'tasg_abo_retry_at_' . (int) $sub->id );
	}

	private function remember_payment( \WC_Order $order, object $sub, array $result ): void {
		$order->set_transaction_id( '' !== $result['charge'] ? $result['charge'] : $result['id'] );

		// PayPal: hand the saved payment method (vault id) on from the last order.
		if ( in_array( (string) $sub->gateway, PayPalCharger::GATEWAY_IDS, true ) ) {
			$last  = wc_get_order( (int) $sub->last_order_id );
			$vault = $last ? (string) $last->get_meta( PayPalCharger::VAULT_META ) : '';

			if ( '' !== $vault ) {
				$order->update_meta_data( PayPalCharger::VAULT_META, $vault );
			}
			return;
		}

		// The Stripe references only belong on orders paid through Stripe.
		if ( 0 !== strpos( (string) $sub->gateway, 'stripe' ) ) {
			return;
		}

		$order->update_meta_data( '_stripe_intent_id', $result['id'] );

		// Hand the Stripe references on from the last order, so the chain continues.
		$last = wc_get_order( (int) $sub->last_order_id );
		foreach ( array( '_stripe_customer_id', '_stripe_source_id' ) as $meta_key ) {
			$value = $last ? (string) $last->get_meta( $meta_key ) : '';
			if ( '' !== $value ) {
				$order->update_meta_data( $meta_key, $value );
			}
		}
		$order->update_meta_data( '_stripe_charge_captured', 'yes' );
	}

	/**
	 * Link the new order as the last order of the subscription. Done before any
	 * payment hooks run, so a faulty hook of another plugin cannot leave the
	 * subscription pointing at an older order. The address correction is used up.
	 */
	private function link_order( \WC_Order $order, object $sub ): void {
		$this->repository->update(
			(int) $sub->id,
			array(
				'last_order_id'    => $order->get_id(),
				'address_override' => null,
			)
		);
	}

	private function complete( \WC_Order $order, object $sub, array $result ): void {
		try {
			$this->remember_payment( $order, $sub, $result );
			$order->save();
			$this->link_order( $order, $sub );

			// Standard WooCommerce payment completion: stock, emails and hooks run as usual.
			$order->payment_complete( $order->get_transaction_id() );

			$target = Settings::renewal_status();
			if ( $order->get_status() !== $target ) {
				$order->update_status( $target, __( 'Status set by the subscription settings.', 'tasg-abo' ) );
			}

			Log::info( sprintf( 'Subscription #%d: order #%d completed with status "%s".', (int) $sub->id, $order->get_id(), $order->get_status() ) );

			do_action( 'tasg_abo_renewal_completed', $order, $sub );
		} catch ( \Throwable $e ) {
			// The customer has paid. Never retry, only make the problem visible.
			$this->link_order( $order, $sub );
			$order->add_order_note( 'Payment taken, but a follow-up step failed: ' . $e->getMessage() );
			Log::critical( sprintf( 'Subscription #%d order #%d is paid but a follow-up step failed: %s', (int) $sub->id, $order->get_id(), $e->getMessage() ) );
		}
	}

	/**
	 * Payment accepted but not final yet (for example SEPA direct debit).
	 */
	private function hold( \WC_Order $order, object $sub, array $result ): void {
		$this->remember_payment( $order, $sub, $result );
		$order->save();
		$this->link_order( $order, $sub );
		$order->update_status( 'on-hold', __( 'Payment is still processing at the payment provider.', 'tasg-abo' ) );
		Log::info( sprintf( 'Subscription #%d: order #%d is on hold, payment still processing.', (int) $sub->id, $order->get_id() ) );
	}

	/**
	 * Nothing was charged. Put the subscription back so the renewal is tried
	 * again after a pause (1 hour, then 2), or give up at the third failure.
	 */
	private function retry_later( object $sub, ?\WC_Order $order, string $message ): void {
		if ( $order ) {
			$order->delete( true );
		}

		$key   = 'tasg_abo_retry_' . (int) $sub->id;
		$tries = (int) get_transient( $key ) + 1;

		Log::warning( sprintf( 'Subscription #%d renewal attempt %d failed: %s', (int) $sub->id, $tries, $message ) );

		if ( $tries >= self::MAX_TEMPORARY_ERRORS ) {
			$this->clear_retry( $sub );
			$this->declined( $sub, null, $message );
			return;
		}

		set_transient( $key, $tries, DAY_IN_SECONDS );
		set_transient( 'tasg_abo_retry_at_' . (int) $sub->id, time() + $tries * HOUR_IN_SECONDS, DAY_IN_SECONDS );

		// Restore the original due date, so the same payment is retried with the same idempotency key.
		$this->repository->update(
			(int) $sub->id,
			array(
				'next_payment'  => $sub->next_payment,
				'reminder_sent' => 1,
			)
		);
	}

	/**
	 * The payment was refused. No order stays, the subscription goes on and the same
	 * order is tried again later; the customer is asked to check the payment method.
	 * After the last try the subscription ends.
	 */
	private function declined( object $sub, ?\WC_Order $order, string $message ): void {
		if ( $order ) {
			$order->delete( true );
		}

		$at = DeclineRetry::record( $sub );

		if ( 0 === $at ) {
			$this->fail( $sub, null, $message );
			return;
		}

		Log::warning( sprintf( 'Subscription #%d: payment declined (%s). Try %d of %d on %s UTC.', (int) $sub->id, $message, DeclineRetry::get( (int) $sub->id )['tries'], count( DeclineRetry::DAYS ), gmdate( 'Y-m-d H:i', $at ) ) );

		// Back to the original due date, so the same order is retried and no period is skipped.
		$this->repository->update(
			(int) $sub->id,
			array(
				'next_payment'  => $sub->next_payment,
				'reminder_sent' => 1,
			)
		);

		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['tasg_abo_payment_retry'] ) ) {
			$sent = $emails['tasg_abo_payment_retry']->trigger( (int) $sub->id );
			Log::info( sprintf( 'Subscription #%d: payment retry email %s.', (int) $sub->id, $sent ? 'sent' : 'NOT sent' ) );
		}
	}

	/**
	 * Final failure: no order stays, the subscription ends, the customer is told.
	 */
	private function fail( object $sub, ?\WC_Order $order, string $message ): void {
		if ( $order ) {
			$order->delete( true );
		}

		Log::error( sprintf( 'Subscription #%d could not be renewed: %s', (int) $sub->id, $message ) );

		( new Actions( $this->repository ) )->cancel( $sub );

		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['tasg_abo_failed'] ) ) {
			$emails['tasg_abo_failed']->trigger( (int) $sub->id );
		}
	}
}
