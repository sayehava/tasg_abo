<?php
/**
 * Handles the queued "tasg_abo_card_check" job: warns the customer when the
 * saved card expires before the next order. Checked once per due date.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Gateways\CardCheck;
use TasgAbo\Log;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class CardNoticeHandler {

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'tasg_abo_card_check', array( $this, 'handle' ) );
	}

	public function handle( $subscription_id ): void {
		$row = $this->repository->find( (int) $subscription_id );

		if ( ! $row || 'active' !== $row->status ) {
			return;
		}

		// Done once per due date, whatever the result, so it is never repeated.
		$this->repository->update( (int) $row->id, array( 'card_notice_for' => $row->next_payment ) );

		// A warning only helps if the customer can pick another payment method.
		if ( ! Settings::card_notice() || ! Settings::customer_may( 'payment' ) ) {
			return;
		}

		$info = CardCheck::info( $row );

		if ( ! $info || $info['expires'] >= strtotime( $row->next_payment . ' UTC' ) ) {
			return;
		}

		$emails = WC()->mailer()->get_emails();

		if ( isset( $emails['tasg_abo_card_expiring'] ) ) {
			$ok = $emails['tasg_abo_card_expiring']->trigger( (int) $row->id );
			Log::info( sprintf( 'Subscription #%d: card expiry warning %s.', (int) $row->id, $ok ? 'sent' : 'NOT sent' ) );
		}
	}
}
