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

use TasgAbo\Plans\Period;

defined( 'ABSPATH' ) || exit;

final class Actions {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function cancel( object $sub ): bool {
		if ( ! in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			return false;
		}

		$this->repository->update( (int) $sub->id, array( 'status' => 'cancelled' ) );
		do_action( 'tasg_abo_subscription_cancelled', (int) $sub->id );

		return true;
	}

	/**
	 * Skip one order, then carry on as before.
	 */
	public function skip( object $sub ): bool {
		if ( 'active' !== $sub->status ) {
			return false;
		}

		// Reminder already sent: the coming order is the one to skip, move on one period.
		if ( (int) $sub->reminder_sent === 1 ) {
			$due = strtotime( $sub->next_payment . ' UTC' );
			$this->repository->update(
				(int) $sub->id,
				array(
					'next_payment'  => gmdate( 'Y-m-d H:i:s', Period::add( $due, (int) $sub->period_value, (string) $sub->period_unit ) ),
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
		if ( 'active' !== $sub->status ) {
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

	public function run( object $sub, string $action ): bool {
		switch ( $action ) {
			case 'cancel':
				return $this->cancel( $sub );
			case 'skip':
				return $this->skip( $sub );
			case 'pause':
				return $this->pause( $sub );
			case 'continue':
				return $this->resume( $sub );
		}

		return false;
	}
}
