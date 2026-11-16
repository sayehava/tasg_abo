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
		$this->available_email_ids = array( 'tasg_abo_reminder', 'tasg_abo_pause_reminder', 'tasg_abo_failed', 'tasg_abo_card_expiring' );
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
			'plan_url'         => array( 'Link: change the plan', home_url( '/' ) ),
			'subscription_id'  => array( 'Subscription number', '12' ),
			'cancel_content'   => array( 'Content of the cancellation', 'Ordinary cancellation of the contract' ),
			'received_at'      => array( 'Date and time the cancellation was received', '1 January 2027 10:00' ),
			'end_date'         => array( 'Date the contract ends', '1 February 2027' ),
			'term_note'        => array( 'Note about the minimum term (empty without one)', 'Your minimum term runs until 1 January. Skip and pause are not possible until then.' ),
			'payment_url'      => array( 'Link: change the payment method', home_url( '/' ) ),
			'card_name'        => array( 'Card (expiring card email)', 'Visa **** 4242' ),
			'card_expiry'      => array( 'Card expiry (expiring card email)', '12/2030' ),
		);
	}

	public function get_shortcodes() {
		$shortcodes = array();

		foreach ( $this->definitions() as $key => $definition ) {
			$sample = $definition[1];

			// Sample links differ from each other, so the editor shows which link is which.
			if ( home_url( '/' ) === $sample ) {
				$sample = add_query_arg( 'tasg_abo_sample', $key, $sample );
			}

			$shortcodes[] = array(
				'name'        => self::PREFIX . $key,
				'description' => $definition[0],
				'group'       => 'Tasg Abo',
				'callback'    => function ( $data, $shortcode_atts = array() ) use ( $key, $sample ) {
					$render = isset( $data['render_data'] ) && is_array( $data['render_data'] ) ? $data['render_data'] : array();

					// A real order picked in the preview: show the data of its subscription,
					// or at least the customer and product of that order.
					$order = $render['order'] ?? null;

					if ( $order instanceof \WC_Order && empty( $render['is_sample'] ) ) {
						$real = SubscriptionEmail::values_for_order( $order->get_id() );

						if ( null !== $real && isset( $real[ '{' . $key . '}' ] ) ) {
							return nl2br( esc_html( (string) $real[ '{' . $key . '}' ] ) );
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
								return nl2br( esc_html( $own[ $key ] ) );
							}
						}
					}

					// The editor and sample previews show a sample value.
					if ( ! empty( $data['is_placeholder'] ) || ! empty( $render['is_sample'] ) ) {
						return nl2br( esc_html( $sample ) );
					}

					$live = SubscriptionEmail::live_values();

					return isset( $live[ '{' . $key . '}' ] ) ? nl2br( esc_html( (string) $live[ '{' . $key . '}' ] ) ) : '';
				},
			);
		}

		return $shortcodes;
	}
}
