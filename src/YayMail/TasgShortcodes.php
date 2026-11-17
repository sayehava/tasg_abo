<?php
/**
 * The placeholders of our emails as YayMail shortcodes, for example
 * [yaymail_tasg_abo_customer_name]. They appear in the YayMail editor in the
 * group "Tasg Abo". In the editor they show sample values, in a sent email the
 * real values of that email.
 *
 * Written from the YayMail developer documentation (Shortcode class).
 *
 * @package TasgAbo
 */

namespace TasgAbo\YayMail;

use TasgAbo\Emails\SubscriptionEmail;

defined( 'ABSPATH' ) || exit;

final class TasgShortcodes extends \YayMail\Abstracts\BaseShortcode {

	public const PREFIX = 'yaymail_tasg_abo_';

	private static $instance;

	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	public static function get_instance() {
		return self::instance();
	}

	protected function __construct() {
		$this->available_email_ids = array( 'tasg_abo_reminder', 'tasg_abo_pause_reminder', 'tasg_abo_failed', 'tasg_abo_card_expiring', 'tasg_abo_cancel_confirmation', 'tasg_abo_confirmation', 'tasg_abo_payment_retry' );
		parent::__construct();
	}

	/**
	 * Name and sample value of each placeholder.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function definitions(): array {
		return array(
			'customer_name'    => array( 'Customer name', 'Anna Example' ),
			'product_name'     => array( 'Product name', 'Sample product' ),
			'next_date'        => array( 'Date of the next order', wp_date( get_option( 'date_format' ), time() + 3 * DAY_IN_SECONDS ) ),
			'amount'           => array( 'Amount of the next order', '9.99' ),
			'shipping_address' => array( 'Shipping address of the next order', "Anna Example\nSample street 1\n12345 Sample city" ),
			'address_url'      => array( 'Link: correct the address', home_url( '/' ) ),
			'skip_url'         => array( 'Link: skip the next order', home_url( '/' ) ),
			'pause_url'        => array( 'Link: pause', home_url( '/' ) ),
			'continue_url'     => array( 'Link: continue after a pause', home_url( '/' ) ),
			'cancel_url'       => array( 'Link: cancel the subscription', home_url( '/' ) ),
			'subscription_id'  => array( 'Subscription number', '12' ),
			'plan_name'        => array( 'Name of the plan', 'Monthly' ),
			'cancel_content'   => array( 'Content of the cancellation', 'Ordinary cancellation of the contract' ),
			'received_at'      => array( 'Date and time the cancellation was received', '1 January 2027 10:00' ),
			'end_date'         => array( 'Date the contract ends', '1 February 2027' ),
			'term_note'        => array( 'Note about the minimum term (empty without one)', 'Your minimum term runs until 1 January. Skip and pause are not possible until then.' ),
			'payment_url'      => array( 'Link: change the payment method', home_url( '/' ) ),
			'card_name'        => array( 'Card (expiring card email)', 'Visa **** 4242' ),
			'card_expiry'      => array( 'Card expiry (expiring card email)', '12/2030' ),
			'retry_date'       => array( 'Date of the next payment try (payment retry email)', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() + DAY_IN_SECONDS ) ),
			'skip_line'        => array( 'Line with the skip link (empty during the minimum term)', str_replace( '{skip_url}', add_query_arg( 'tasg_abo_sample', 'skip_url', home_url( '/' ) ), __( 'Skip this order: {skip_url}', 'tasg-abo' ) ) ),
			'pause_line'       => array( 'Line with the pause link (empty during the minimum term)', str_replace( '{pause_url}', add_query_arg( 'tasg_abo_sample', 'pause_url', home_url( '/' ) ), __( 'Pause: {pause_url}', 'tasg-abo' ) ) ),
		);
	}

	public function get_shortcodes() {
		$shortcodes = array();
		$samples    = array();

		foreach ( $this->definitions() as $key => $definition ) {
			// Sample links differ from each other, so the editor shows which link is which.
			$samples[ '{' . $key . '}' ] = home_url( '/' ) === $definition[1] ? add_query_arg( 'tasg_abo_sample', $key, $definition[1] ) : $definition[1];
		}

		foreach ( $this->definitions() as $key => $definition ) {

			$shortcodes[] = array(
				'name'        => self::PREFIX . $key,
				'description' => $definition[0],
				'group'       => 'Tasg Abo',
				'callback'    => function ( $data, $shortcode_atts = array() ) use ( $key, $samples ) {
					$atts   = is_array( $shortcode_atts ) ? $shortcode_atts : array();
					$render = isset( $data['render_data'] ) && is_array( $data['render_data'] ) ? $data['render_data'] : array();

					// A real order picked in the preview: show the data of its subscription,
					// or at least the customer and product of that order.
					$order = $render['order'] ?? null;

					if ( $order instanceof \WC_Order && empty( $render['is_sample'] ) ) {
						$real = SubscriptionEmail::values_for_order( $order->get_id() );

						if ( null !== $real && isset( $real[ '{' . $key . '}' ] ) ) {
							return self::format( $key, $real, $atts );
						}

						if ( null === $real ) {
							$items = $order->get_items();
							$first = $items ? reset( $items ) : null;
							$own   = array(
								'customer_name'    => trim( $order->get_formatted_billing_full_name() ),
								'product_name'     => $first ? $first->get_name() : '',
								'shipping_address' => trim( str_replace( array( '<br/>', '<br>', '<br />' ), "\n", $order->get_formatted_shipping_address() ) ),
								'amount'           => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
								'next_date'        => wp_date( get_option( 'date_format' ), time() + 3 * DAY_IN_SECONDS ),
							);

							if ( ! empty( $own[ $key ] ) ) {
								return self::format( $key, array( '{' . $key . '}' => $own[ $key ] ), $atts );
							}
						}
					}

					// The editor and sample previews show a sample value.
					if ( ! empty( $data['is_placeholder'] ) || ! empty( $render['is_sample'] ) ) {
						return self::format( $key, $samples, $atts );
					}

					return self::format( $key, SubscriptionEmail::live_values(), $atts );
				},
			);
		}

		return $shortcodes;
	}

	/**
	 * One placeholder as email HTML. A link with text="..." becomes a clickable text,
	 * for example [yaymail_tasg_abo_address_url text="Change address"]; without it the
	 * plain address is printed, so it still works inside a button's link field. The
	 * skip and pause lines become clickable lines.
	 *
	 * @param array<string,string> $values Placeholder values (with braces) of one source.
	 * @param array                $atts   Attributes written in the shortcode.
	 */
	private static function format( string $key, array $values, array $atts ): string {
		$value = (string) ( $values[ '{' . $key . '}' ] ?? '' );

		if ( '' === $value ) {
			return '';
		}

		// The email worked the texts out in the customer's language before rendering.
		$lines = array(
			'skip_line'  => array( '{skip_url}', (string) ( $values['{skip_link_text}'] ?? __( 'Skip this order', 'tasg-abo' ) ) ),
			'pause_line' => array( '{pause_url}', (string) ( $values['{pause_link_text}'] ?? __( 'Pause your subscription', 'tasg-abo' ) ) ),
		);

		if ( isset( $lines[ $key ] ) && ! empty( $values[ $lines[ $key ][0] ] ) ) {
			$text = ! empty( $atts['text'] ) ? (string) $atts['text'] : $lines[ $key ][1];

			return '<a href="' . esc_url( (string) $values[ $lines[ $key ][0] ] ) . '">' . esc_html( $text ) . '</a>';
		}

		if ( '_url' === substr( $key, -4 ) && ! empty( $atts['text'] ) ) {
			return '<a href="' . esc_url( $value ) . '">' . esc_html( (string) $atts['text'] ) . '</a>';
		}

		return nl2br( esc_html( $value ) );
	}
}
