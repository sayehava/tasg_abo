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

	/**
	 * Message of an error that stopped the plugin from starting, if any.
	 *
	 * @var string
	 */
	private $load_error = '';

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

		// A fault while starting must never take the shop down: show it to admins instead.
		try {
			$this->register_components();
		} catch ( \Throwable $e ) {
			$this->load_error = $e->getMessage();
			error_log( 'Tasg Abo could not start: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			add_action( 'admin_notices', array( $this, 'load_error_notice' ) );
		}
	}

	private function register_components(): void {
		Subscriptions\Schema::maybe_install();
		( new Plans\PlanRepository() )->seed_default();
		( new Subscriptions\Scheduler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\ReminderHandler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\PauseHandler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\CardNoticeHandler( new Subscriptions\Repository() ) )->register();
		( new Renewal\RenewalHandler( new Subscriptions\Repository(), new Renewal\OrderBuilder(), new Gateways\StripeCharger(), new Gateways\PayPalCharger() ) )->register();
		( new Emails\Registry() )->register();
		( new Privacy\PrivacyTools() )->register();
		( new Checkout\CheckoutRules() )->register();
		( new Subscriptions\Creator( new Subscriptions\Repository() ) )->register();
		( new Frontend\LinkHandler() )->register();
		( new Frontend\MyAccount() )->register();
		( new Frontend\ProductToggle() )->register();
		( new Cart\CartHandler() )->register();
		( new Cart\SingleItemRule() )->register();

		if ( is_admin() ) {
			( new Admin\ProductSettings() )->register();
			( new Admin\SettingsPage() )->register();
			( new Admin\PlansPage() )->register();
			( new Admin\SubscriptionsPage() )->register();
			( new Admin\EmailDesigner() )->register();
		}
	}

	public function load_error_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html( sprintf( /* translators: %s: error message */ __( 'Tasg Abo could not start: %s', 'tasg-abo' ), $this->load_error ) )
			. '</p></div>';
	}

	public function woocommerce_missing_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Tasg Abo needs WooCommerce to be installed and active.', 'tasg-abo' )
			. '</p></div>';
	}
}
