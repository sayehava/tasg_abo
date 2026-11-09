<?php
/**
 * Plugin bootstrap: wires every component together.
 *
 * @package TasgAbo
 */

namespace TasgAbo;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Attach the hooks that must exist before WooCommerce is loaded.
	 */
	public function boot(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Tell WooCommerce this plugin works with custom order tables.
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TASG_ABO_FILE, true );
		}
	}

	/**
	 * Start the plugin once WooCommerce is available.
	 */
	public function init(): void {
		load_plugin_textdomain( 'tasg-abo', false, dirname( plugin_basename( TASG_ABO_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		Subscriptions\Schema::maybe_install();
		( new Subscriptions\Scheduler( new Subscriptions\Repository() ) )->register();
		( new Checkout\CheckoutRules() )->register();
		( new Subscriptions\Creator( new Subscriptions\Repository() ) )->register();
		( new Frontend\ProductToggle() )->register();
		( new Cart\CartHandler() )->register();

		if ( is_admin() ) {
			( new Admin\ProductSettings() )->register();
		}
	}

	public function woocommerce_missing_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Tasg Abo needs WooCommerce to be installed and active.', 'tasg-abo' )
			. '</p></div>';
	}
}
