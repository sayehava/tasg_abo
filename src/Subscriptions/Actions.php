<?php
/**
 * State changes a customer (or the owner) can make to a subscription.
 * Used by the email links and later by the My Account page.
 *
 * Statuses: active, paused, cancelled.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Log;
use TasgAbo\Plans\Period;
use TasgAbo\Plans\Plan;

defined( 'ABSPATH' ) || exit;

final class Actions {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * True while the minimum term of the subscription is still running.
	 */
	public function in_term( object $sub ): bool {
		return ! empty( $sub->term_ends ) && strtotime( $sub->term_ends . ' UTC' ) > time();
	}

	/**
	 * A cancellation by the customer. Inside the minimum term it is only noted and
	 * takes effect when the term is over: orders continue until then. Outside the
	 * term the subscription ends at once.
	 */
	public function request_cancel( object $sub ): bool {
		if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			return false;
		}

		if ( ! $this->in_term( $sub ) ) {
			return $this->cancel( $sub );
		}

		if ( empty( $sub->cancel_after ) ) {
			$this->repository->update( (int) $sub->id, array( 'cancel_after' => $sub->term_ends ) );
			Log::info( sprintf( 'Subscription #%d: cancellation noted, ends with the minimum term (%s UTC).', (int) $sub->id, $sub->term_ends ) );
			do_action( 'tasg_abo_subscription_cancel_requested', (int) $sub->id );
		}

		return true;
	}

	/**
	 * Take back a cancellation that was noted inside the minimum term.
	 */
	public function keep( object $sub ): bool {
		if ( 'active' !== $sub->status || empty( $sub->cancel_after ) ) {
			return false;
		}

		$this->repository->update( (int) $sub->id, array( 'cancel_after' => null ) );

		return true;
	}

	/**
	 * Ends the subscription at once, whatever the term. Used for failed payments,
	 * erasure requests, pauses nobody answered and by the shop owner.
	 */
	public function cancel( object $sub ): bool {
		if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			return false;
		}

		// A cancelled subscription keeps no corrected address and no payment method link.
		$this->repository->update(
			(int) $sub->id,
			array(
				'status'           => 'cancelled',
				'cancel_after'     => null,
				'address_override' => null,
				'payment_token_id' => 0,
			)
		);
		Log::info( sprintf( 'Subscription #%d: cancelled.', (int) $sub->id ) );
		do_action( 'tasg_abo_subscription_cancelled', (int) $sub->id );

		return true;
	}

	/**
	 * Skip one order, then carry on as before.
	 */
	public function skip( object $sub ): bool {
		if ( 'active' !== $sub->status || $this->in_term( $sub ) ) {
			return false;
		}

		// Reminder already sent: the coming order is the one to skip, move on one period.
		if ( (int) $sub->reminder_sent === 1 ) {
			$due = strtotime( $sub->next_payment . ' UTC' );
			$this->repository->update(
				(int) $sub->id,
				array(
					'next_payment'  => gmdate( 'Y-m-d H:i:s', Period::add( $due, (int) $sub->period_value, (string) $sub->period_unit, Period::anchor_day( $sub ) ) ),
					'reminder_sent' => 0,
					'skip_next'     => 0,
				)
			);
			return true;
		}

		$this->repository->update( (int) $sub->id, array( 'skip_next' => 1 ) );

		return true;
	}

	/**
	 * Pause for the next period only. The order due next is not made. From its
	 * due date on, the customer is asked to continue or cancel.
	 */
	public function pause( object $sub ): bool {
		if ( 'active' !== $sub->status || $this->in_term( $sub ) ) {
			return false;
		}

		$this->repository->update(
			(int) $sub->id,
			array(
				'status'          => 'paused',
				'reminder_sent'   => 0,
				'skip_next'       => 0,
				'pause_reminders' => 0,
			)
		);

		return true;
	}

	/**
	 * Continue after a pause. The next order follows after the notice time of its plan.
	 */
	public function resume( object $sub ): bool {
		if ( 'paused' !== $sub->status ) {
			return false;
		}

		$this->repository->update(
			(int) $sub->id,
			array(
				'status'          => 'active',
				'next_payment'    => gmdate( 'Y-m-d H:i:s', time() + (int) $sub->reminder_minutes * MINUTE_IN_SECONDS + 5 * MINUTE_IN_SECONDS ),
				'reminder_sent'   => 0,
				'pause_reminders' => 0,
			)
		);

		return true;
	}

	/**
	 * Switch to another plan. For an active subscription the next order is placed one
	 * new period after the last order, but never sooner than the plan's reminder time
	 * from now, and a fresh reminder goes out with the new date and price. A paused
	 * subscription only changes its plan.
	 */
	public function change_plan( object $sub, Plan $plan ): bool {
		if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			return false;
		}

		$data = array(
			'plan_id'          => $plan->id,
			'plan_name'        => $plan->title(),
			'period_value'     => $plan->value,
			'period_unit'      => $plan->unit,
			'reminder_minutes' => $plan->reminder_minutes,
		);

		if ( 'active' === $sub->status ) {
			$last = Address::last_order( $sub );
			$date = $last ? $last->get_date_created() : null;
			$base = $date ? $date->getTimestamp() : time();

			$next     = Period::add( $base, $plan->value, $plan->unit, Period::anchor_day( $sub ) );
			$earliest = time() + $plan->reminder_minutes * MINUTE_IN_SECONDS + 5 * MINUTE_IN_SECONDS;

			$data['next_payment']  = gmdate( 'Y-m-d H:i:s', max( $next, $earliest ) );
			$data['reminder_sent'] = 0;
			$data['skip_next']     = 0;
		}

		$this->repository->update( (int) $sub->id, $data );
		Log::info( sprintf( 'Subscription #%d: plan changed to plan %d (next order %s UTC).', (int) $sub->id, $plan->id, $data['next_payment'] ?? 'unchanged' ) );

		return true;
	}

	/**
	 * Choose which saved Stripe payment method is charged. 0 goes back to the
	 * method of the last order. Only the customer's own tokens are accepted.
	 * No card data is handled here, only the id of the token WooCommerce keeps.
	 */
	public function set_payment_token( object $sub, int $token_id ): bool {
		if ( 0 !== strpos( (string) $sub->gateway, 'stripe' ) ) {
			return false;
		}

		if ( $token_id > 0 ) {
			$token = \WC_Payment_Tokens::get( $token_id );

			if ( ! $token || (int) $token->get_user_id() !== (int) $sub->user_id || 0 !== strpos( (string) $token->get_gateway_id(), 'stripe' ) ) {
				return false;
			}
		}

		$this->repository->update( (int) $sub->id, array( 'payment_token_id' => $token_id ) );
		Log::info( sprintf( 'Subscription #%d: payment method choice changed.', (int) $sub->id ) );

		return true;
	}

	public function run( object $sub, string $action ): bool {
		switch ( $action ) {
			case 'cancel':
				$done = $this->request_cancel( $sub );
				break;
			case 'cancel_now':
				$done = $this->cancel( $sub );
				break;
			case 'keep':
				$done = $this->keep( $sub );
				break;
			case 'skip':
				$done = $this->skip( $sub );
				break;
			case 'pause':
				$done = $this->pause( $sub );
				break;
			case 'continue':
				$done = $this->resume( $sub );
				break;
			default:
				return false;
		}

		if ( $done ) {
			Log::info( sprintf( 'Subscription #%d: action "%s" done.', (int) $sub->id, $action ) );
		}

		return $done;
	}
}
