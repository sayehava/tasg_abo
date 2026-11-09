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

	public const ACTIONS = array( 'cancel', 'skip', 'pause', 'continue' );

	public static function url( object $subscription, string $action ): string {
		return add_query_arg(
			array(
				'tasg_abo' => $action,
				'key'      => $subscription->cancel_key,
			),
			home_url( '/' )
		);
	}
}
