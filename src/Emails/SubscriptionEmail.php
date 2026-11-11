<?php
/**
 * Shared behaviour of every subscription email: loads the subscription,
 * switches to the language of the original order (WPML), fills placeholders
 * and renders one common template. Text and subject are editable in
 * WooCommerce > Settings > Emails and the layout can be designed in YayMail.
 *
 * Placeholders: {customer_name} {product_name} {next_date} {amount}
 *               {cancel_url} {skip_url} {pause_url} {continue_url}
 *               {shipping_address} {address_url} {payment_url} {plan_url}
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Pricing;
use TasgAbo\Subscriptions\Address;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

abstract class SubscriptionEmail extends \WC_Email {

	/**
	 * Texts from the plugin's own email editor for the language being sent.
	 *
	 * @var array
	 */
	private $texts = array(
		'subject' => '',
		'heading' => '',
		'body'    => '',
	);

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
			'{payment_url}'      => '',
			'{plan_url}'         => '',
			'{card_name}'        => '',
			'{card_expiry}'      => '',
		);
	}

	/**
	 * Placeholders only one email needs. Overridden where required.
	 *
	 * @return array<string,string>
	 */
	protected function extra_placeholders( object $row ): array {
		return array();
	}

	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['body'] = array(
			'title'       => __( 'Email text', 'tasg-abo' ),
			'type'        => 'textarea',
			'description' => __( 'Placeholders: {customer_name} {product_name} {next_date} {amount} {cancel_url} {skip_url} {pause_url} {continue_url} {shipping_address} {address_url} {payment_url} {plan_url} {card_name} {card_expiry}', 'tasg-abo' ),
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
		$row = ( new Repository() )->find( $subscription_id );

		return $row ? $this->deliver( $row ) : false;
	}

	/**
	 * Send the email for a subscription row. With $test_to the row may be a made
	 * up sample, and the mail goes to that address even if the email is switched off.
	 */
	public function deliver( object $row, string $test_to = '' ): bool {
		$user    = get_userdata( (int) $row->user_id );
		$product = wc_get_product( (int) $row->product_id );

		if ( ! $user || ! $product ) {
			return false;
		}

		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $row->language );

		$sent = false;

		try {
			$this->object    = $row;
			$this->recipient = '' !== $test_to ? $test_to : $user->user_email;
			$this->texts     = EmailTexts::get( $this->id, EmailTexts::resolve_key( (string) $row->language ) );

			$amount = (float) Pricing::subscriber_price( $product, (int) $row->plan_id ) * (int) $row->quantity;

			$this->placeholders['{customer_name}'] = $user->display_name;
			$this->placeholders['{product_name}']  = $product->get_name();
			$this->placeholders['{next_date}']     = wp_date( get_option( 'date_format' ), strtotime( $row->next_payment . ' UTC' ) );
			$this->placeholders['{amount}']        = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
			$this->placeholders['{cancel_url}']    = Links::url( $row, 'cancel' );
			$this->placeholders['{skip_url}']      = Links::url( $row, 'skip' );
			$this->placeholders['{pause_url}']     = Links::url( $row, 'pause' );
			$this->placeholders['{continue_url}']  = Links::url( $row, 'continue' );

			// The address of the next order, with a link to correct it for that order.
			$this->placeholders['{shipping_address}'] = Address::formatted( Address::shipping( $row ) );
			$this->placeholders['{address_url}']      = Links::url( $row, 'address' );
			$this->placeholders['{payment_url}']      = Links::url( $row, 'payment' );
			$this->placeholders['{plan_url}']         = Links::url( $row, 'plan' );
			$this->placeholders                       = array_merge( $this->placeholders, $this->extra_placeholders( $row ) );

			if ( ( '' !== $test_to || $this->is_enabled() ) && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			if ( is_string( $previous ) && '' !== $previous ) {
				do_action( 'wpml_switch_language', $previous );
			}
		}

		return $sent;
	}

	public function get_subject() {
		return '' !== $this->texts['subject'] ? $this->format_string( $this->texts['subject'] ) : parent::get_subject();
	}

	public function get_heading() {
		return '' !== $this->texts['heading'] ? $this->format_string( $this->texts['heading'] ) : parent::get_heading();
	}

	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'body'               => $this->format_string( '' !== $this->texts['body'] ? $this->texts['body'] : $this->get_option( 'body', static::default_body() ) ),
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
