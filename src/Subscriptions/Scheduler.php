<?php
/**
 * Regular check (every 5 minutes) run by WooCommerce's own Action Scheduler (no server cron).
 *
 * On every run it finds subscriptions that need a reminder email or a renewal
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


defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const GROUP = 'tasg-abo';

	/** How often the check runs. Short plans for tests need a fine grid. */
	private const TICK_SECONDS = 5 * MINUTE_IN_SECONDS;

	/** Reminders go out this many minutes early, covering the delay of the tick. */
	private const MARGIN_MINUTES = 15;

	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'schedule_tick' ) );
		add_action( 'tasg_abo_tick', array( $this, 'run_tick' ) );
	}

	public function schedule_tick(): void {
		// A tick scheduled with an older interval is replaced, so changes reach existing sites.
		if ( get_option( 'tasg_abo_tick_interval' ) !== (string) self::TICK_SECONDS ) {
			as_unschedule_all_actions( 'tasg_abo_tick', array(), self::GROUP );
			update_option( 'tasg_abo_tick_interval', (string) self::TICK_SECONDS, false );
		}

		if ( false === as_next_scheduled_action( 'tasg_abo_tick', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time(), self::TICK_SECONDS, 'tasg_abo_tick', array(), self::GROUP );
		}
	}

	public function run_tick(): void {
		$now = time();

		$now_utc = gmdate( 'Y-m-d H:i:s', $now );

		foreach ( $this->repository->needing_reminder( $now_utc, self::MARGIN_MINUTES ) as $row ) {
			$this->queue( 'tasg_abo_send_reminder', (int) $row->id );
		}

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
