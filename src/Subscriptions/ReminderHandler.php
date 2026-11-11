<?php
/**
 * Handles the queued "tasg_abo_send_reminder" job.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Plans\Period;

defined( 'ABSPATH' ) || exit;

final class ReminderHandler {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'tasg_abo_send_reminder', array( $this, 'handle' ) );
	}

	public function handle( $subscription_id ): void {
		$row = $this->repository->find( (int) $subscription_id );

		if ( ! $row || 'active' !== $row->status || (int) $row->reminder_sent === 1 ) {
			return;
		}

		$due = strtotime( $row->next_payment . ' UTC' );

		// The customer chose to skip: roll forward one period, no email, no charge.
		if ( (int) $row->skip_next === 1 ) {
			$this->repository->update(
				(int) $row->id,
				array(
					'next_payment'  => gmdate( 'Y-m-d H:i:s', Period::add( $due, (int) $row->period_value, (string) $row->period_unit, Period::anchor_day( $row ) ) ),
					'skip_next'     => 0,
					'reminder_sent' => 0,
				)
			);
			return;
		}

		// Never let the notice be shorter than the plan promises (five minutes of safety added).
		$earliest = time() + (int) $row->reminder_minutes * MINUTE_IN_SECONDS + 5 * MINUTE_IN_SECONDS;
		$pushed = $due < $earliest;
		if ( $pushed ) {
			$this->repository->update( (int) $row->id, array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $earliest ) ) );
		}

		$emails = WC()->mailer()->get_emails();

		if ( isset( $emails['tasg_abo_reminder'] ) && $emails['tasg_abo_reminder']->trigger( (int) $row->id ) ) {
			$this->repository->update( (int) $row->id, array( 'reminder_sent' => 1 ) );
			return;
		}

		// Not sent (email switched off, no recipient, mail failure). Put the date back so
		// it does not slide forward on every check, and say so once a day. Nothing is
		// charged until a reminder has gone out.
		if ( $pushed ) {
			$this->repository->update( (int) $row->id, array( 'next_payment' => $row->next_payment ) );
		}

		$key = 'tasg_abo_reminder_log_' . (int) $row->id;
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, DAY_IN_SECONDS );
			wc_get_logger()->error(
				sprintf( 'Reminder email for subscription #%d was not sent, so it will not be charged. Is the email switched on?', (int) $row->id ),
				array( 'source' => 'tasg-abo' )
			);
		}
	}
}
