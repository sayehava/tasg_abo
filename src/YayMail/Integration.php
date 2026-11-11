<?php
/**
 * Connects our emails to YayMail when the shop owner wants to design them there.
 *
 * The hooks are always added, because YayMail may load after this plugin. Every
 * callback checks first that YayMail is really there and chosen, and a fault
 * never breaks a page: it is logged and the normal email template is used.
 *
 * @package TasgAbo
 */

namespace TasgAbo\YayMail;

use TasgAbo\Emails\SubscriptionEmail;
use TasgAbo\Log;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class Integration {

	public function register(): void {
		add_action( 'yaymail_register_emails', array( $this, 'register_emails' ) );
		add_action( 'yaymail_register_shortcodes', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * @param object $yaymail_emails YayMail's registry of email templates.
	 */
	public function register_emails( $yaymail_emails ): void {
		if ( ! Settings::use_yaymail() ) {
			return;
		}

		try {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				if ( $email instanceof SubscriptionEmail ) {
					$yaymail_emails->register( TasgEmail::for_email( $email ) );
				}
			}
		} catch ( \Throwable $e ) {
			Log::error( 'Could not register the emails with YayMail: ' . $e->getMessage() );
		}
	}

	public function register_shortcodes(): void {
		if ( ! Settings::use_yaymail() ) {
			return;
		}

		try {
			TasgShortcodes::instance();
		} catch ( \Throwable $e ) {
			Log::error( 'Could not register the shortcodes with YayMail: ' . $e->getMessage() );
		}
	}
}
