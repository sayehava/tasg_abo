<?php
/**
 * Shared behaviour of every subscription email: loads the subscription,
 * switches to the language of the original order (WPML), fills placeholders
 * and renders one common template. Text and subject are editable in
 * WooCommerce > Settings > Emails and the layout can be designed in YayMail.
 *
 * Placeholders: {customer_name} {product_name} {next_date} {amount}
 *               {cancel_url} {skip_url} {pause_url} {continue_url}
 *               {shipping_address} {address_url} {payment_url} {term_note}
 *               {skip_line} {pause_line} (whole lines, empty during the minimum term)
 *
 * Recipient and name come from the billing data of the subscription's order, as with
 * the WooCommerce order emails, so a changed checkout email or a guest order is respected.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Pricing;
use TasgAbo\Settings;
use TasgAbo\Subscriptions\Address;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

abstract class SubscriptionEmail extends \WC_Email {

	/**
	 * Placeholder values of the email being sent right now. YayMail shortcodes read them.
	 *
	 * @var array<string,string>
	 */
	private static $live = array();

	/**
	 * @return array<string,string> placeholder (with braces) => value
	 */
	public static function live_values(): array {
		return self::$live;
	}

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
		// One file name per email, so that YayMail can tell the emails apart.
		$slug                 = str_replace( '_', '-', $id );
		$this->template_html  = 'emails/' . $slug . '.php';
		$this->template_plain = 'emails/plain/' . $slug . '.php';
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
			'{term_note}'        => '',
			'{card_name}'        => '',
			'{card_expiry}'      => '',
			'{skip_line}'        => '',
			'{pause_line}'       => '',
		);
	}

	/**
	 * The newest order of the subscription, the first one if the newest is gone.
	 * Emails go where WooCommerce sends the order emails: the billing email of the order.
	 */
	private static function order_of( object $row ): ?\WC_Order {
		foreach ( array( (int) ( $row->last_order_id ?? 0 ), (int) ( $row->origin_order_id ?? 0 ) ) as $order_id ) {
			$order = $order_id ? wc_get_order( $order_id ) : false;

			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Billing email of the order, the account email only when the order has none.
	 */
	private static function recipient_of( object $row ): string {
		$order = self::order_of( $row );
		$email = $order ? (string) $order->get_billing_email() : '';

		if ( '' === $email ) {
			$user  = get_userdata( (int) $row->user_id );
			$email = $user ? (string) $user->user_email : '';
		}

		return $email;
	}

	/**
	 * Billing name of the order, the account name only when the order has none.
	 */
	private static function name_of( object $row ): string {
		$order = self::order_of( $row );
		$name  = $order ? trim( $order->get_formatted_billing_full_name() ) : '';

		if ( '' === $name ) {
			$user = get_userdata( (int) $row->user_id );
			$name = $user ? (string) $user->display_name : '';
		}

		return $name;
	}

	/**
	 * Skip and pause only exist after the minimum term and when the owner allows them.
	 */
	private static function action_open( object $row, string $action ): bool {
		$in_term = ! empty( $row->term_ends ) && strtotime( $row->term_ends . ' UTC' ) > time();

		return ! $in_term && Settings::customer_may( $action );
	}

	/**
	 * One sentence about the minimum term, empty for a subscription without a running term.
	 */
	private function term_note( object $row ): string {
		$format = get_option( 'date_format' );

		if ( ! empty( $row->cancel_after ) ) {
			/* translators: %s: date the minimum term ends */
			return sprintf( __( 'Your cancellation is noted: your subscription ends with the minimum term on %s.', 'tasg-abo' ), wp_date( $format, strtotime( $row->cancel_after . ' UTC' ) ) );
		}

		if ( ! empty( $row->term_ends ) && strtotime( $row->term_ends . ' UTC' ) > time() ) {
			/* translators: %s: date the minimum term ends */
			return sprintf( __( 'Your minimum term runs until %s. Skip and pause are not possible until then.', 'tasg-abo' ), wp_date( $format, strtotime( $row->term_ends . ' UTC' ) ) );
		}

		return '';
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
			'description' => __( 'Placeholders: {customer_name} {product_name} {next_date} {amount} {cancel_url} {skip_url} {pause_url} {continue_url} {shipping_address} {address_url} {payment_url} {term_note} {card_name} {card_expiry} {skip_line} {pause_line}', 'tasg-abo' ),
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
		$product = wc_get_product( (int) $row->product_id );

		if ( ! $product ) {
			return false;
		}

		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $row->language );

		// Also switch the WordPress locale, so this plugin's own texts, dates and the
		// default email texts come out in the customer's language, even in a background job.
		$locale   = EmailTexts::locale_for( (string) $row->language );
		$switched = '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale );

		$sent = false;

		try {
			$this->object    = $row;
			$this->recipient = '' !== $test_to ? $test_to : self::recipient_of( $row );
			// With YayMail the design and text come from YayMail, not from our editor.
			$this->texts = Settings::use_yaymail()
				? array(
					'subject' => '',
					'heading' => '',
					'body'    => '',
				)
				: EmailTexts::get( $this->id, EmailTexts::resolve_key( (string) $row->language ) );

			$this->placeholders = $this->values_for( $row, $product );
			self::$live         = $this->placeholders;

			if ( ( '' !== $test_to || $this->is_enabled() ) && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			self::$live = array();

			if ( $switched ) {
				restore_previous_locale();
			}

			if ( is_string( $previous ) && '' !== $previous ) {
				do_action( 'wpml_switch_language', $previous );
			}
		}

		return $sent;
	}

	/**
	 * All placeholder values of this email for one subscription.
	 *
	 * @return array<string,string> placeholder (with braces) => value
	 */
	public function values_for( object $row, \WC_Product $product ): array {
		$amount = (float) Pricing::subscriber_price( $product, (int) $row->plan_id ) * (int) $row->quantity;
		$values = $this->placeholders;

		$values['{customer_name}'] = self::name_of( $row );
		$values['{product_name}']  = $product->get_name();
		$values['{next_date}']     = wp_date( get_option( 'date_format' ), strtotime( $row->next_payment . ' UTC' ) );
		$values['{amount}']        = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		$values['{cancel_url}']    = Links::url( $row, 'cancel' );
		$values['{skip_url}']      = self::action_open( $row, 'skip' ) ? Links::url( $row, 'skip' ) : '';
		$values['{pause_url}']     = self::action_open( $row, 'pause' ) ? Links::url( $row, 'pause' ) : '';

		// Whole lines, empty during the minimum term, so no label is left without its link.
		$values['{skip_line}']  = '' !== $values['{skip_url}'] ? str_replace( '{skip_url}', $values['{skip_url}'], __( 'Skip this order: {skip_url}', 'tasg-abo' ) ) : '';
		$values['{pause_line}'] = '' !== $values['{pause_url}'] ? str_replace( '{pause_url}', $values['{pause_url}'], __( 'Pause: {pause_url}', 'tasg-abo' ) ) : '';

		// Link texts in the customer's language, worked out now: YayMail may switch the
		// language while it renders, so its shortcodes take them from here.
		$values['{skip_link_text}']  = __( 'Skip this order', 'tasg-abo' );
		$values['{pause_link_text}'] = __( 'Pause your subscription', 'tasg-abo' );
		$values['{continue_url}']  = Links::url( $row, 'continue' );

		// The address of the next order, with a link to correct it for that order.
		$values['{shipping_address}'] = Address::formatted( Address::shipping( $row ) );
		$values['{address_url}']      = Links::url( $row, 'address' );
		$values['{payment_url}']      = Links::url( $row, 'payment' );
		$values['{term_note}']        = $this->term_note( $row );

		return array_merge( $values, $this->extra_placeholders( $row ) );
	}

	/**
	 * Real values for the YayMail preview: the subscription an order belongs to
	 * (its first order or a renewal order). Null when the order is no subscription order.
	 *
	 * @return array<string,string>|null
	 */
	public static function values_for_order( int $order_id ): ?array {
		$row = ( new Repository() )->find_by_order( $order_id );

		if ( ! $row ) {
			return null;
		}

		$product = wc_get_product( (int) $row->product_id );

		if ( ! $product ) {
			return null;
		}

		$values = array();

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! $email instanceof self ) {
				continue;
			}

			try {
				$values = array_merge( $values, $email->values_for( $row, $product ) );
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $values ? $values : null;
	}

	/**
	 * What the shop owner saved in WooCommerce > Settings > Emails, or empty. The form
	 * defaults are not used, because they were built in the site language before the
	 * language of the customer was switched on.
	 */
	private function saved_setting( string $key ): string {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings' );

		return is_array( $saved ) && ! empty( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
	}

	public function get_subject() {
		if ( '' !== $this->texts['subject'] ) {
			return $this->format_string( $this->texts['subject'] );
		}

		$saved = $this->saved_setting( 'subject' );

		return $this->format_string( '' !== $saved ? $saved : $this->get_default_subject() );
	}

	public function get_heading() {
		if ( '' !== $this->texts['heading'] ) {
			return $this->format_string( $this->texts['heading'] );
		}

		$saved = $this->saved_setting( 'heading' );

		return $this->format_string( '' !== $saved ? $saved : $this->get_default_heading() );
	}

	/**
	 * Body text: the editor text of this language, else the one saved in the WooCommerce
	 * email settings, else the built-in default in the customer's language.
	 */
	private function body_text(): string {
		$body = '' !== $this->texts['body'] ? $this->texts['body'] : $this->saved_setting( 'body' );

		if ( '' === $body ) {
			return static::default_body();
		}

		// A text written before the minimum term existed has no {term_note}. The note is
		// added at the end, so the customer is told about the term whatever the text says.
		if ( 'tasg_abo_reminder' === $this->id && false === strpos( $body, '{term_note}' ) ) {
			$body .= "\n\n{term_note}";
		}

		return $body;
	}

	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'body'               => $this->format_string( $this->body_text() ),
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
