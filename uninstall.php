<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Settings and queued jobs are removed. The subscriptions table is kept on
 * purpose, because it holds customer data and payment references that must not
 * disappear without a deliberate decision. Drop it by hand if it is not needed.
 *
 * @package TasgAbo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'tasg_abo_tick', 'tasg_abo_send_reminder', 'tasg_abo_renew', 'tasg_abo_pause_reminder' ) as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}

foreach ( array( 'tasg_abo_renewal_status', 'tasg_abo_reminder_hours', 'tasg_abo_pause_reminder_days', 'tasg_abo_rewrites_flushed' ) as $option ) {
	delete_option( $option );
}
