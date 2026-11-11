<?php
/**
 * WooCommerce > Settings > Subscriptions > Diagnostics: a read-only overview for support and testing.
 *
 * Shows versions, the plugin state, subscription counts, the next due subscriptions,
 * the state of the background jobs and the latest plugin log lines. It shows no card
 * data (there is none) and no keys, only whether a Stripe key is present.
 * The one action is "Run the check now", which runs the same check the scheduler
 * runs every five minutes.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Log;
use TasgAbo\Plans\Period;
use TasgAbo\Plans\PlanRepository;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Schema;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

	public function register(): void {
		add_action( 'admin_post_tasg_abo_run_check', array( $this, 'handle_run_check' ) );
		add_action( 'admin_post_tasg_abo_download_log', array( $this, 'handle_download_log' ) );
	}

	public function handle_run_check(): void {
		check_admin_referer( 'tasg_abo_run_check' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.' );
		}

		do_action( 'tasg_abo_tick' );

		if ( class_exists( '\ActionScheduler_QueueRunner' ) ) {
			\ActionScheduler_QueueRunner::instance()->run( 'Tasg Abo diagnostics' );
		}

		wp_safe_redirect( add_query_arg( 'ran', '1', SettingsHub::url( 'diagnostics' ) ) );
		exit;
	}

	private function table( string $title, array $rows ): void {
		echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:280px">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function yes_no( bool $value ): string {
		return $value ? 'yes' : 'no';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		echo '<div class="tasg-abo-section">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ran'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>The check ran. Reload this page to see the result.</p></div>';
		}

		$stripe   = (array) get_option( 'woocommerce_stripe_settings', array() );
		$test     = isset( $stripe['testmode'] ) && 'yes' === $stripe['testmode'];
		$key_name = $test ? 'test_secret_key' : 'secret_key';
		$emails   = WC()->mailer()->get_emails();

		$this->table(
			'Environment',
			array(
				'Plugin version'        => TASG_ABO_VERSION,
				'Database version'      => (string) get_option( 'tasg_abo_db_version', 'not installed' ),
				'PHP'                   => PHP_VERSION,
				'WordPress'             => get_bloginfo( 'version' ),
				'WooCommerce'           => defined( 'WC_VERSION' ) ? WC_VERSION : 'not found',
				'WooCommerce Stripe'    => defined( 'WC_STRIPE_VERSION' ) ? WC_STRIPE_VERSION : 'not found',
				'WooCommerce PayPal'    => class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ? 'active' : 'not found',
				'WPML'                  => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : 'not found',
				'Action Scheduler'      => class_exists( 'ActionScheduler' ) ? 'active' : 'not found',
				'Time zone'             => wp_timezone_string(),
				'Server time (UTC)'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->table(
			'Plugin state',
			array(
				'Test phase (managers only)' => $this->yes_no( Settings::admin_only() ),
				'Renewal order status'       => Settings::renewal_status(),
				'Email design setting'       => Settings::email_designer(),
				'YayMail found'              => $this->yes_no( Settings::yaymail_installed() ),
				'Emails designed in'         => Settings::use_yaymail() ? 'YayMail' : 'plugin editor',
				'PayPal switched on'         => $this->yes_no( Settings::paypal_enabled() ),
				'Stripe mode'                => $test ? 'test' : 'live',
				'Stripe secret key present'  => $this->yes_no( ! empty( $stripe[ $key_name ] ) ),
				'Reminder email switched on' => isset( $emails['tasg_abo_reminder'] ) ? $this->yes_no( $emails['tasg_abo_reminder']->is_enabled() ) : 'email not registered',
				'Plans defined'              => (string) count( ( new PlanRepository() )->all() ),
				'Products offering a plan'   => (string) count(
					wc_get_products(
						array(
							'limit'      => -1,
							'return'     => 'ids',
							'meta_key'   => '_tasg_abo_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						)
					)
				),
			)
		);

		$counts = array();
		$table  = Schema::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ) as $row ) { // phpcs:ignore WordPress.DB
				$counts[ 'Subscriptions: ' . $row->status ] = (string) $row->total;
			}
		}
		$this->table( 'Subscriptions', $counts ? $counts : array( 'Subscriptions' => 'none yet (or table missing)' ) );

		$this->render_due( $table );
		$this->render_jobs();
		$this->render_log();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1.5em">';
		wp_nonce_field( 'tasg_abo_run_check' );
		echo '<input type="hidden" name="action" value="tasg_abo_run_check">';
		echo '<button class="button button-primary">Run the check now</button> ';
		echo '<span class="description">Runs the check that normally runs every five minutes and processes waiting jobs.</span></form></div>';
	}

	private function render_due( string $table ): void {
		global $wpdb;

		echo '<h2>Next subscriptions due</h2><table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( 'ID', 'Plan', 'Next payment (UTC)', 'Reminder sent', 'Status' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('active','paused') ORDER BY next_payment ASC LIMIT 10" ); // phpcs:ignore WordPress.DB

		if ( ! $rows ) {
			echo '<tr><td colspan="5">None.</td></tr>';
		}

		foreach ( $rows as $row ) {
			echo '<tr><td>' . (int) $row->id . '</td><td>' . esc_html( Period::row_label( $row ) ) . '</td><td>' . esc_html( (string) $row->next_payment ) . '</td><td>' . esc_html( $this->yes_no( 1 === (int) $row->reminder_sent ) ) . '</td><td>' . esc_html( $row->status ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_jobs(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->table( 'Background jobs', array( 'Action Scheduler' => 'not available' ) );
			return;
		}

		$next = as_next_scheduled_action( 'tasg_abo_tick', array(), 'tasg-abo' );
		$rows = array( 'Regular check next runs' => is_int( $next ) ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : ( $next ? 'running now' : 'NOT SCHEDULED' ) );

		foreach ( array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ) as $status ) {
			$rows[ 'Jobs ' . $status ] = (string) count( as_get_scheduled_actions( array( 'group' => 'tasg-abo', 'status' => $status, 'per_page' => -1 ), 'ids' ) );
		}

		$this->table( 'Background jobs (group tasg-abo)', $rows );

		$failed = as_get_scheduled_actions( array( 'group' => 'tasg-abo', 'status' => 'failed', 'per_page' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );

		if ( $failed ) {
			$list = array();
			foreach ( $failed as $action ) {
				$date                       = $action->get_schedule()->get_date();
				$list[ $action->get_hook() ] = wp_json_encode( $action->get_args() ) . ' at ' . ( $date ? $date->format( 'Y-m-d H:i:s' ) . ' UTC' : '' );
			}
			$this->table( 'Latest failed jobs', $list );
		}
	}

	/**
	 * Lines of the plugin log (WooCommerce log source "tasg-abo"), oldest first,
	 * from the log files, or from the log table when WooCommerce keeps logs in the database.
	 *
	 * @return string[]
	 */
	private function collect_log( int $max_lines, bool $problems_only ): array {
		global $wpdb;

		$lines = array();

		if ( defined( 'WC_LOG_DIR' ) ) {
			$files = (array) glob( WC_LOG_DIR . 'tasg-abo-*.log' );
			usort(
				$files,
				static function ( $a, $b ) {
					return filemtime( $a ) <=> filemtime( $b );
				}
			);

			foreach ( array_slice( $files, -3 ) as $file ) {
				$lines = array_merge( $lines, $this->tail( $file, 2000 ) );
			}
		}

		$log_table = $wpdb->prefix . 'woocommerce_log';
		if ( ! $lines && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, level, message FROM {$log_table} WHERE source = %s ORDER BY log_id DESC LIMIT 2000", Log::SOURCE ) ); // phpcs:ignore WordPress.DB
			foreach ( array_reverse( $rows ) as $row ) {
				$lines[] = $row->timestamp . ' ' . strtoupper( $row->level ) . ' ' . $row->message;
			}
		}

		if ( $problems_only ) {
			$lines = array_values( array_filter( $lines, static function ( $line ) {
				return (bool) preg_match( '/\b(WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\b/', $line );
			} ) );
		}

		return array_slice( $lines, -$max_lines );
	}

	private function render_log(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$problems = isset( $_GET['log'] ) && 'problems' === $_GET['log'];
		$base     = SettingsHub::url( 'diagnostics' );
		$lines    = $this->collect_log( 300, $problems );

		echo '<h2>Plugin log</h2>';
		echo '<p>Everything the plugin does is logged here (subscription and order numbers and results, no card, address or email data). '
			. '<a href="' . esc_url( $base ) . '">All</a> | <a href="' . esc_url( add_query_arg( 'log', 'problems', $base ) ) . '">Warnings and errors only</a> | '
			. '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tasg_abo_download_log' ), 'tasg_abo_download_log' ) ) . '">Download</a></p>';
		echo '<pre style="max-width:1100px;max-height:420px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;white-space:pre-wrap">' . esc_html( $lines ? implode( "\n", $lines ) : 'No log lines yet. Either nothing has run, or nothing has gone wrong. The same log is under WooCommerce > Status > Logs, source tasg-abo.' ) . '</pre>';
	}

	public function handle_download_log(): void {
		check_admin_referer( 'tasg_abo_download_log' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.' );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tasg-abo-log-' . gmdate( 'Y-m-d-His' ) . '.txt"' );

		echo esc_html( implode( "\n", $this->collect_log( 5000, false ) ) );
		exit;
	}

	/**
	 * Last lines of a file, reading only its end.
	 *
	 * @return string[]
	 */
	private function tail( string $path, int $count ): array {
		$size = (int) filesize( $path );
		$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $fh || $size < 1 ) {
			return array();
		}

		$chunk = min( $size, 262144 );
		fseek( $fh, -$chunk, SEEK_END );
		$data = (string) fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array_slice( explode( "\n", trim( $data ) ), -$count );
	}
}
