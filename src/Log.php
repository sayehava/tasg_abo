<?php
/**
 * The plugin's activity log. Written to the WooCommerce log with the source
 * "tasg-abo" (WooCommerce > Status > Logs) and shown on the diagnostics page.
 *
 * Only ids and results are logged: subscription and order numbers, statuses,
 * dates. Never card or bank data, never addresses, never email contents.
 *
 * @package TasgAbo
 */

namespace TasgAbo;

defined( 'ABSPATH' ) || exit;

final class Log {

	public const SOURCE = 'tasg-abo';

	public static function write( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
		}
	}

	public static function info( string $message ): void {
		self::write( 'info', $message );
	}

	public static function warning( string $message ): void {
		self::write( 'warning', $message );
	}

	public static function error( string $message ): void {
		self::write( 'error', $message );
	}

	public static function critical( string $message ): void {
		self::write( 'critical', $message );
	}
}
