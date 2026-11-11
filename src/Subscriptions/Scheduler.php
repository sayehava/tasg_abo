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
 *   tasg_abo_pause_reminder( subscription_id )
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

use TasgAbo\Settings;

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

	public function run_tick(): void {
		$now = time();

		$remind_until = gmdate( 'Y-m-d H:i:s', $now + Settings::reminder_hours() * HOUR_IN_SECONDS );
		foreach ( $this->repository->needing_reminder( $remind_until ) as $row ) {
			$this->queue( 'tasg_abo_send_reminder', (int) $row->id );
		}

		$now_utc = gmdate( 'Y-m-d H:i:s', $now );

		foreach ( $this->repository->due( $now_utc ) as $row ) {
			$this->queue( 'tasg_abo_renew', (int) $row->id );
		}

		foreach ( $this->repository->paused_due( $now_utc ) as $row ) {
			$this->queue( 'tasg_abo_pause_reminder', (int) $row->id );
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
