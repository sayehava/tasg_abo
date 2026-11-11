<?php
/**
 * Handles the queued "tasg_abo_pause_reminder" job.
 *
 * Once a pause is over the customer is asked to continue or cancel, up to
 * three times. If nothing happens after the last reminder the subscription is
 * cancelled. Nothing is charged and no order is made while paused.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class PauseHandler {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'tasg_abo_pause_reminder', array( $this, 'handle' ) );
	}

	public function handle( $subscription_id ): void {
		$row = $this->repository->find( (int) $subscription_id );

		if ( ! $row || 'paused' !== $row->status || strtotime( $row->next_payment . ' UTC' ) > time() ) {
			return;
		}

		$sent = (int) $row->pause_reminders;

		if ( $sent >= Settings::PAUSE_MAX_REMINDERS ) {
			( new Actions( $this->repository ) )->cancel( $row );
			return;
		}

		$emails = WC()->mailer()->get_emails();

		if ( isset( $emails['tasg_abo_pause_reminder'] ) ) {
			$emails['tasg_abo_pause_reminder']->trigger( (int) $row->id );
		}

		// Counted even if the mail failed, so a paused subscription cannot wait forever.
		$this->repository->update(
			(int) $row->id,
			array(
				'pause_reminders' => $sent + 1,
				'next_payment'    => gmdate( 'Y-m-d H:i:s', time() + Settings::pause_reminder_days() * DAY_IN_SECONDS ),
			)
		);
	}
}
