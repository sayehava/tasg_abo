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

			$shortcodes[] = array(
				'name'        => self::PREFIX . $key,
				'description' => $definition[0],
				'group'       => 'Tasg Abo',
				'callback'    => function ( $data, $shortcode_atts = array() ) use ( $key, $sample ) {
					$render = isset( $data['render_data'] ) && is_array( $data['render_data'] ) ? $data['render_data'] : array();

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
