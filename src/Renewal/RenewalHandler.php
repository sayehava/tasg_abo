<?php
/**
 * Handles the queued "tasg_abo_renew" job: creates the renewal order and
 * charges the saved payment method.
 *
 * - Success: the order is paid and gets the status chosen in the settings,
 *   exactly like a normal order (stock, emails, invoice automation).
 * - Payment declined: no order is kept, the subscription is cancelled and the
 *   customer is told.
 * - Temporary problem (network, Stripe outage): nothing is charged, the
 *   renewal is tried again on the next hourly check, up to three times.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Renewal;

use TasgAbo\Gateways\StripeCharger;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class RenewalHandler {

	private const MAX_TEMPORARY_ERRORS = 3;

	private $repository;
	private $builder;
	private $charger;

	public function __construct( Repository $repository, OrderBuilder $builder, StripeCharger $charger ) {
		$this->repository = $repository;
		$this->builder    = $builder;
		$this->charger    = $charger;
	}

	public function register(): void {
		add_action( 'tasg_abo_renew', array( $this, 'handle' ) );
	}

	public function handle( $subscription_id ): void {
		$row = $this->repository->find( (int) $subscription_id );

		if ( ! $row || 'active' !== $row->status || 1 !== (int) $row->reminder_sent ) {
			return;
		}

		$due = strtotime( $row->next_payment . ' UTC' );

		if ( $due > time() ) {
			return;
		}

		// Move the schedule on first. Only one worker can win this step.
		$next = $due + (int) $row->interval_weeks * WEEK_IN_SECONDS;
		if ( $next <= time() + Settings::reminder_hours() * HOUR_IN_SECONDS ) {
			$next = time() + (int) $row->interval_weeks * WEEK_IN_SECONDS;
		}

		if ( ! $this->repository->claim( (int) $row->id, gmdate( 'Y-m-d H:i:s', $next ) ) ) {
			return;
		}

		if ( ! $this->charger->supports( (string) $row->gateway ) ) {
			$this->fail( $row, null, 'Payment gateway cannot be charged again: ' . $row->gateway );
			return;
		}

		$order = $this->builder->build( $row );

		if ( ! $order ) {
			$this->fail( $row, null, 'Renewal order could not be created.' );
			return;
		}

		$result = (float) $order->get_total() > 0
			? $this->charger->charge( $order, $row, 'tasg-abo-' . (int) $row->id . '-' . md5( $row->next_payment ) )
			: array(
				'status'  => 'succeeded',
				'id'      => '',
				'charge'  => '',
				'message' => '',
			);

		switch ( $result['status'] ) {
			case 'succeeded':
				delete_transient( 'tasg_abo_retry_' . (int) $row->id );
				$this->complete( $order, $row, $result );
				break;

			case 'processing':
				$this->hold( $order, $row, $result );
				break;

			case 'error':
				$this->retry_later( $row, $order, $result['message'] );
				break;

			default:
				$this->fail( $row, $order, $result['message'] );
		}
	}

	private function remember_payment( \WC_Order $order, object $sub, array $result ): void {
		$order->set_transaction_id( '' !== $result['charge'] ? $result['charge'] : $result['id'] );
		$order->update_meta_data( '_stripe_intent_id', $result['id'] );
		$order->update_meta_data( '_stripe_customer_id', $sub->gateway_customer );
		$order->update_meta_data( '_stripe_source_id', $sub->gateway_method );
		$order->update_meta_data( '_stripe_charge_captured', 'yes' );
	}

	private function complete( \WC_Order $order, object $sub, array $result ): void {
		$this->remember_payment( $order, $sub, $result );
		$order->save();

		// Standard WooCommerce payment completion: stock, emails and hooks run as usual.
		$order->payment_complete( $order->get_transaction_id() );

		$target = Settings::renewal_status();
		if ( $order->get_status() !== $target ) {
			$order->update_status( $target, __( 'Status set by the subscription settings.', 'tasg-abo' ) );
		}

		do_action( 'tasg_abo_renewal_completed', $order, $sub );
	}

	/**
	 * Payment accepted but not final yet (for example SEPA direct debit).
	 */
	private function hold( \WC_Order $order, object $sub, array $result ): void {
		$this->remember_payment( $order, $sub, $result );
		$order->update_status( 'on-hold', __( 'Payment is still processing at Stripe.', 'tasg-abo' ) );
	}

	/**
	 * Nothing was charged. Put the subscription back so the next hourly check
	 * repeats the renewal, or give up after a few tries.
	 */
	private function retry_later( object $sub, \WC_Order $order, string $message ): void {
		$order->delete( true );

		$key   = 'tasg_abo_retry_' . (int) $sub->id;
		$tries = (int) get_transient( $key ) + 1;

		if ( $tries >= self::MAX_TEMPORARY_ERRORS ) {
			delete_transient( $key );
			$this->fail( $sub, null, $message );
			return;
		}

		set_transient( $key, $tries, DAY_IN_SECONDS );

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
	 * Final failure: no order stays, the subscription ends, the customer is told.
	 */
	private function fail( object $sub, ?\WC_Order $order, string $message ): void {
		if ( $order ) {
			$order->delete( true );
		}

		wc_get_logger()->error(
			sprintf( 'Subscription #%d could not be renewed: %s', (int) $sub->id, $message ),
			array( 'source' => 'tasg-abo' )
		);

		( new Actions( $this->repository ) )->cancel( $sub );

		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['tasg_abo_failed'] ) ) {
			$emails['tasg_abo_failed']->trigger( (int) $sub->id );
		}
	}
}
