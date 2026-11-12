<?php
/**
 * Plugin Name:       Tasg Abo
 * Description:       Lets customers turn a WooCommerce product into a subscription. Renewal orders are created on schedule and charged through the saved Stripe or PayPal payment method.
 * Version:           0.3.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            sayehava
 * Text Domain:       tasg-abo
 * Domain Path:       /languages
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package TasgAbo
 */

defined( 'ABSPATH' ) || exit;

define( 'TASG_ABO_VERSION', '0.3.1' );
define( 'TASG_ABO_FILE', __FILE__ );
define( 'TASG_ABO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TASG_ABO_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'TasgAbo\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = TASG_ABO_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\TasgAbo\Plugin::instance()->boot();
