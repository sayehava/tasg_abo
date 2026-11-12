<?php
/**
 * Links a customer can open from an email without logging in.
 * Each subscription has a secret random key, so the link itself is the proof.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Links {

	public const ACTIONS = array( 'cancel', 'skip', 'pause', 'continue', 'keep', 'address', 'plan', 'payment' );

	public static function url( object $subscription, string $action ): string {
		// Cancelling always happens on the cancel page, whoever links to it.
		if ( 'cancel' === $action ) {
			$page = \TasgAbo\Cancellation\CancelPage::url( $subscription );

			if ( '' !== $page ) {
				return $page;
			}
		}

		return add_query_arg(
			array(
				'tasg_abo' => $action,
				'key'      => $subscription->cancel_key,
			),
			home_url( '/' )
		);
	}
}
