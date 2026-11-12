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
		register_shutdown_function( array( $this, 'log_fatal' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Records a fatal PHP error that happened inside this plugin, which would
	 * otherwise only show as a blank page. Errors of other plugins are ignored.
	 */
	public function log_fatal(): void {
		$error = error_get_last();

		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		$file = str_replace( '\\', '/', (string) $error['file'] );
		$dir  = str_replace( '\\', '/', TASG_ABO_DIR );

		if ( 0 !== strpos( $file, $dir ) ) {
			return;
		}

		Log::critical( sprintf( 'PHP fatal error in %s line %d: %s', substr( $file, strlen( $dir ) ), (int) $error['line'], $error['message'] ) );
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
		add_action( 'admin_init', array( new Plans\PlanRepository(), 'register_strings' ) );
		( new Subscriptions\Scheduler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\ReminderHandler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\PauseHandler( new Subscriptions\Repository() ) )->register();
		( new Subscriptions\CardNoticeHandler( new Subscriptions\Repository() ) )->register();
		( new Renewal\RenewalHandler( new Subscriptions\Repository(), new Renewal\OrderBuilder(), new Gateways\StripeCharger(), new Gateways\PayPalCharger() ) )->register();
		( new Emails\Registry() )->register();
		( new YayMail\Integration() )->register();
		( new Privacy\PrivacyTools() )->register();
		( new Checkout\CheckoutRules() )->register();
		( new Subscriptions\Creator( new Subscriptions\Repository() ) )->register();
		( new Cancellation\CancelPage() )->register();
		( new Frontend\LinkHandler() )->register();
		( new Frontend\MyAccount() )->register();
		( new Frontend\ProductToggle() )->register();
		( new Cart\CartHandler() )->register();
		( new Cart\SingleItemRule() )->register();

		if ( is_admin() ) {
			( new Admin\ProductSettings() )->register();

			// The "Subscriptions" tab in WooCommerce > Settings, with these sections inside.
			$sections = array(
				'settings'      => new Admin\SettingsPage(),
				'plans'         => new Admin\PlansPage(),
				'subscriptions' => new Admin\SubscriptionsPage(),
				'emails'        => new Admin\EmailDesigner(),
				'diagnostics'   => new Admin\DiagnosticsPage(),
			);

			foreach ( $sections as $section ) {
				$section->register();
			}

			( new Admin\SettingsHub( $sections ) )->register();
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
