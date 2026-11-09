<?php
/**
 * "We will charge you again" email, sent at least 48 hours before a renewal.
 *
 * It is a real WooCommerce email: text and subject are editable under
 * WooCommerce > Settings > Emails and the layout can be designed in YayMail.
 * The email is sent in the language the customer ordered in (WPML).
 *
 * Placeholders: {customer_name} {product_name} {next_date} {amount}
 *               {cancel_url} {skip_url} {pause_url}
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Pricing;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class ReminderEmail extends \WC_Email {

	public function __construct() {
		$this->id             = 'tasg_abo_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Subscription reminder', 'tasg-abo' );
		$this->description    = __( 'Sent to the customer at least 48 hours before the next subscription order is charged.', 'tasg-abo' );
		$this->template_html  = 'emails/tasg-abo-reminder.php';
		$this->template_plain = 'emails/plain/tasg-abo-reminder.php';
		$this->template_base  = TASG_ABO_DIR . 'templates/';
		$this->placeholders   = array(
			'{customer_name}' => '',
			'{product_name}'  => '',
			'{next_date}'     => '',
			'{amount}'        => '',
			'{cancel_url}'    => '',
			'{skip_url}'      => '',
			'{pause_url}'     => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your next order of {product_name} on {next_date}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your next subscription order', 'tasg-abo' );
	}

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nwe will charge you again for your next order of {product_name} on {next_date}. Amount: {amount} plus shipping.\n\nYou do not need to do anything to continue.\n\nSkip this order: {skip_url}\nPause: {pause_url}\nCancel your subscription: {cancel_url}",
			'tasg-abo'
		);
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['body'] = array(
			'title'       => __( 'Email text', 'tasg-abo' ),
			'type'        => 'textarea',
			'description' => __( 'Available placeholders: {customer_name} {product_name} {next_date} {amount} {cancel_url} {skip_url} {pause_url}', 'tasg-abo' ),
			'default'     => self::default_body(),
			'desc_tip'    => false,
		);
	}

	/**
	 * Send the reminder for one subscription.
	 *
	 * @return bool True when the email was handed to the mailer.
	 */
	public function trigger( int $subscription_id ): bool {
		$row     = ( new Repository() )->find( $subscription_id );
		$user    = $row ? get_userdata( (int) $row->user_id ) : false;
		$product = $row ? wc_get_product( (int) $row->product_id ) : false;

		if ( ! $row || ! $user || ! $product ) {
			return false;
		}

		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $row->language );

		$sent = false;

		try {
			$this->object    = $row;
			$this->recipient = $user->user_email;

			$amount = (float) Pricing::subscriber_price( $product ) * (int) $row->quantity;

			$this->placeholders['{customer_name}'] = $user->display_name;
			$this->placeholders['{product_name}']  = $product->get_name();
			$this->placeholders['{next_date}']     = wp_date( get_option( 'date_format' ), strtotime( $row->next_payment . ' UTC' ) );
			$this->placeholders['{amount}']        = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
			$this->placeholders['{cancel_url}']    = Links::url( $row, 'cancel' );
			$this->placeholders['{skip_url}']      = Links::url( $row, 'skip' );
			$this->placeholders['{pause_url}']     = Links::url( $row, 'pause' );

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			if ( is_string( $previous ) && '' !== $previous ) {
				do_action( 'wpml_switch_language', $previous );
			}
		}

		return $sent;
	}

	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'body'               => $this->format_string( $this->get_option( 'body', self::default_body() ) ),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
