<?php
/**
 * Shared behaviour of every subscription email: loads the subscription,
 * switches to the language of the original order (WPML), fills placeholders
 * and renders one common template. Text and subject are editable in
 * WooCommerce > Settings > Emails and the layout can be designed in YayMail.
 *
 * Placeholders: {customer_name} {product_name} {next_date} {amount}
 *               {cancel_url} {skip_url} {pause_url} {continue_url}
 *               {shipping_address} {address_url}
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Pricing;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

abstract class SubscriptionEmail extends \WC_Email {

	/**
	 * Default email text with placeholders, already translated.
	 */
	abstract public static function default_body(): string;

	protected function setup_subscription_email( string $id, string $title, string $description ): void {
		$this->id             = $id;
		$this->customer_email = true;
		$this->title          = $title;
		$this->description    = $description;
		$this->template_html  = 'emails/tasg-abo-email.php';
		$this->template_plain = 'emails/plain/tasg-abo-email.php';
		$this->template_base  = TASG_ABO_DIR . 'templates/';
		$this->placeholders   = array(
			'{customer_name}' => '',
			'{product_name}'  => '',
			'{next_date}'     => '',
			'{amount}'        => '',
			'{cancel_url}'    => '',
			'{skip_url}'      => '',
			'{pause_url}'     => '',
			'{continue_url}'  => '',
			'{shipping_address}' => '',
			'{address_url}'      => '',
		);
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['body'] = array(
			'title'       => __( 'Email text', 'tasg-abo' ),
			'type'        => 'textarea',
			'description' => __( 'Placeholders: {customer_name} {product_name} {next_date} {amount} {cancel_url} {skip_url} {pause_url} {continue_url} {shipping_address} {address_url}', 'tasg-abo' ),
			'default'     => static::default_body(),
			'desc_tip'    => false,
		);
	}

	/**
	 * Send this email for one subscription.
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
			$this->placeholders['{continue_url}']  = Links::url( $row, 'continue' );

			// The address that will be used comes from the customer account.
			$customer = new \WC_Customer( (int) $row->user_id );
			$address  = $customer->get_shipping();
			if ( empty( $address['address_1'] ) ) {
				$address = $customer->get_billing();
			}
			$formatted = str_replace( array( '<br/>', '<br>', '<br />' ), "\n", WC()->countries->get_formatted_address( $address ) );

			$this->placeholders['{shipping_address}'] = wp_strip_all_tags( $formatted );
			$this->placeholders['{address_url}']      = wc_get_endpoint_url( 'edit-address', 'shipping', wc_get_page_permalink( 'myaccount' ) );

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
			'body'               => $this->format_string( $this->get_option( 'body', static::default_body() ) ),
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
