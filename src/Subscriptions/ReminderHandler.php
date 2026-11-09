<?php
/**
 * Handles the queued "tasg_abo_send_reminder" job.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class ReminderHandler {

	/** Customers always get at least this much notice. One hour of safety is added. */
	private const MIN_NOTICE = 49 * HOUR_IN_SECONDS;

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
					'next_payment'  => gmdate( 'Y-m-d H:i:s', $due + (int) $row->interval_weeks * WEEK_IN_SECONDS ),
					'skip_next'     => 0,
					'reminder_sent' => 0,
				)
			);
			return;
		}

		// Never let the notice be shorter than promised.
		$earliest = time() + self::MIN_NOTICE;
		if ( $due < $earliest ) {
			$this->repository->update( (int) $row->id, array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $earliest ) ) );
		}

		$emails = WC()->mailer()->get_emails();

		if ( isset( $emails['tasg_abo_reminder'] ) && $emails['tasg_abo_reminder']->trigger( (int) $row->id ) ) {
			$this->repository->update( (int) $row->id, array( 'reminder_sent' => 1 ) );
		}
	}
}
