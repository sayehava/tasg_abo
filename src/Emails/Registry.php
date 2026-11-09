<?php
/**
 * Registers the plugin's emails with WooCommerce.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

defined( 'ABSPATH' ) || exit;

final class Registry {

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'add_emails' ) );
	}

	public function add_emails( array $emails ): array {
		$emails['tasg_abo_reminder'] = new ReminderEmail();

		return $emails;
	}
}
