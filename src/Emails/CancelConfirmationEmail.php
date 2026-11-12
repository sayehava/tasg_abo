<?php
/**
 * Confirms a cancellation at once: what was cancelled, when it was received and when the
 * contract ends. Online cancellation in Germany (cancellation button) requires this
 * confirmation in text form, so it should stay switched on.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

use TasgAbo\Subscriptions\Actions;

defined( 'ABSPATH' ) || exit;

final class CancelConfirmationEmail extends SubscriptionEmail {

	/**
	 * Type and reason of the cancellation being confirmed, set by the cancellation form
	 * right before sending.
	 *
	 * @var array{type:string,reason:string}
	 */
	public static $details = array(
		'type'   => 'ordinary',
		'reason' => '',
	);

	public function __construct() {
		$this->setup_subscription_email(
			'tasg_abo_cancel_confirmation',
			__( 'Cancellation confirmation', 'tasg-abo' ),
			__( 'Sent at once when a customer cancels: content, date and time of receipt and the end of the contract. Required for the cancellation button, keep it switched on.', 'tasg-abo' )
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'We received your cancellation of {product_name}', 'tasg-abo' );
	}

	public function get_default_heading() {
		return __( 'Your cancellation is confirmed', 'tasg-abo' );
	}

	public static function default_body(): string {
		return __(
			"Hello {customer_name},\n\nwe confirm your cancellation.\n\nContract: {product_name}, subscription no. {subscription_id}\nContent: {cancel_content}\nReceived: {received_at}\nThe contract ends: {end_date}\n\n{term_note}",
			'tasg-abo'
		);
	}

	protected function extra_placeholders( object $row ): array {
		$end      = Actions::end_timestamp( $row );
		$format   = get_option( 'date_format' );
		$extra    = 'extraordinary' === self::$details['type'];
		$content  = $extra
			/* translators: %s: reason given by the customer */
			? sprintf( __( 'Extraordinary cancellation. Reason: %s', 'tasg-abo' ), self::$details['reason'] )
			: __( 'Ordinary cancellation of the contract', 'tasg-abo' );

		return array(
			'{subscription_id}' => (string) (int) $row->id,
			'{cancel_content}'  => $content,
			'{received_at}'     => wp_date( $format . ' ' . get_option( 'time_format' ) ),
			'{end_date}'        => wp_date( $format, $end ),
		);
	}
}
