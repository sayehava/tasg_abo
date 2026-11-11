<?php
/**
 * Registers one of our subscription emails with YayMail, so it can be designed
 * there (YayMail > Email templates). Loaded only when YayMail is active and the
 * shop owner chose YayMail for the email design.
 *
 * Written from the YayMail developer documentation (Email class and Register new
 * template). Not tested against a live YayMail yet.
 *
 * @package TasgAbo
 */

namespace TasgAbo\YayMail;

use TasgAbo\Emails\SubscriptionEmail;

defined( 'ABSPATH' ) || exit;

final class TasgEmail extends \YayMail\Abstracts\BaseEmail {

	/**
	 * One instance per email id.
	 *
	 * @var TasgEmail[]
	 */
	private static $instances = array();

	/** Our emails carry no WooCommerce order. */
	public $email_types = array( YAYMAIL_NON_ORDER_EMAILS );

	private $default_heading = '';
	private $default_body    = '';

	public static function for_email( SubscriptionEmail $email ): TasgEmail {
		if ( ! isset( self::$instances[ $email->id ] ) ) {
			self::$instances[ $email->id ] = new self( $email );
		}

		return self::$instances[ $email->id ];
	}

	public static function for_id( string $id ): ?TasgEmail {
		return self::$instances[ $id ] ?? null;
	}

	/**
	 * The YayMail documentation uses both names, so both exist.
	 */
	public static function instance() {
		return self::$instances ? reset( self::$instances ) : null;
	}

	public static function get_instance() {
		return self::instance();
	}

	protected function __construct( SubscriptionEmail $email ) {
		// The same id as the WooCommerce email, as YayMail asks.
		$this->id        = $email->id;
		$this->title     = $email->get_title();
		$this->recipient = 'Customer';
		$this->source    = array(
			'plugin_id'   => 'tasg-abo',
			'plugin_name' => 'Tasg Abo',
		);

		$this->default_heading = $email->get_default_heading();
		$this->default_body    = $email::default_body();
	}

	/**
	 * Our placeholders {name} become YayMail shortcodes [yaymail_tasg_abo_name].
	 */
	private function as_html( string $text ): string {
		$text = preg_replace( '/\{(\w+)\}/', '[' . TasgShortcodes::PREFIX . '$1]', $text );

		return wpautop( esc_html( (string) $text ) );
	}

	public function get_default_elements() {
		return \YayMail\Elements\ElementsLoader::load_elements(
			array(
				array( 'type' => 'Logo' ),
				array(
					'type'       => 'Heading',
					'attributes' => array( 'rich_text' => esc_html( $this->default_heading ) ),
				),
				array(
					'type'       => 'Text',
					'attributes' => array( 'rich_text' => $this->as_html( $this->default_body ) ),
				),
				array( 'type' => 'Footer' ),
			)
		);
	}

	public function get_template_path() {
		return TASG_ABO_DIR . 'templates/yaymail/tasg-abo-email.php';
	}
}
