<?php
/**
 * Hourly check run by WooCommerce's own Action Scheduler (no server cron).
 *
 * Every hour it finds subscriptions that need a reminder email or a renewal
 * and queues one separate Action Scheduler job for each, so a failure in one
 * subscription never blocks another.
 *
 * Jobs queued (group "tasg-abo"):
 *   tasg_abo_send_reminder( subscription_id )
 *   tasg_abo_renew( subscription_id )
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const GROUP = 'tasg-abo';

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'schedule_tick' ) );
		add_action( 'tasg_abo_tick', array( $this, 'run_tick' ) );
	}

	public function schedule_tick(): void {
		if ( false === as_next_scheduled_action( 'tasg_abo_tick', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time(), HOUR_IN_SECONDS, 'tasg_abo_tick', array(), self::GROUP );
		}
	}

	/**
	 * Hours before a payment at which the reminder goes out. Never below 48,
	 * the hourly tick and queue delay then cannot make it late.
	 */
	public static function reminder_lead_hours(): int {
		return max( 48, (int) get_option( 'tasg_abo_reminder_hours', 72 ) );
	}

	public function run_tick(): void {
		$now = time();

		$remind_until = gmdate( 'Y-m-d H:i:s', $now + self::reminder_lead_hours() * HOUR_IN_SECONDS );
		foreach ( $this->repository->needing_reminder( $remind_until ) as $row ) {
			$this->queue( 'tasg_abo_send_reminder', (int) $row->id );
		}

		foreach ( $this->repository->due( gmdate( 'Y-m-d H:i:s', $now ) ) as $row ) {
			$this->queue( 'tasg_abo_renew', (int) $row->id );
		}
	}

	private function queue( string $hook, int $id ): void {
		$args = array( $id );

		if ( false !== as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP );
	}
}
