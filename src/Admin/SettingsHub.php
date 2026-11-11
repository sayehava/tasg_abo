<?php
/**
 * The "Subscriptions" tab in WooCommerce > Settings, with sub-tabs inside, like
 * the tabs of Germanized: Settings, Plans, Subscriptions, Emails, Diagnostics.
 *
 * The tab opens a page of its own that shows the normal WooCommerce settings tab
 * bar, so it looks like a settings tab. A page of its own is used because the
 * sections hold forms of their own, which cannot sit inside the form of the
 * WooCommerce settings screen. Old menu links and bookmarks redirect here.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

defined( 'ABSPATH' ) || exit;

final class SettingsHub {

	public const PAGE = 'tasg-abo';
	public const TAB  = 'tasg_abo';

	/**
	 * Section key => object with a render() method.
	 *
	 * @var array<string,object>
	 */
	private $sections;

	/**
	 * Old page slugs and the section they now live in.
	 */
	private const LEGACY = array(
		'tasg-abo-settings'      => 'settings',
		'tasg-abo-plans'         => 'plans',
		'tasg-abo-subscriptions' => 'subscriptions',
		'tasg-abo-emails'        => 'emails',
		'tasg-abo-diagnostics'   => 'diagnostics',
	);

	/**
	 * @param array<string,object> $sections Section key => page object.
	 */
	public function __construct( array $sections ) {
		$this->sections = $sections;
	}

	public static function url( string $section = 'settings', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'    => self::PAGE,
					'section' => $section,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'admin_init', array( $this, 'redirect_old_links' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Subscriptions', 'tasg-abo' ),
			__( 'Subscriptions', 'tasg-abo' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Subscriptions', 'tasg-abo' );

		return $tabs;
	}

	/**
	 * The WooCommerce tab opens the hub, and links to the old separate pages arrive in the right section.
	 */
	public function redirect_old_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'wc-settings' === $page && self::TAB === $tab ) {
			wp_safe_redirect( self::url( 'settings' ) );
			exit;
		}

		if ( isset( self::LEGACY[ $page ] ) ) {
			$args = $_GET;
			unset( $args['page'] );
			wp_safe_redirect( self::url( self::LEGACY[ $page ], array_map( 'sanitize_text_field', array_map( 'wp_unslash', $args ) ) ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function labels(): array {
		return array(
			'settings'      => __( 'Settings', 'tasg-abo' ),
			'plans'         => __( 'Plans', 'tasg-abo' ),
			'subscriptions' => __( 'Subscriptions', 'tasg-abo' ),
			'emails'        => __( 'Emails', 'tasg-abo' ),
			'diagnostics'   => __( 'Diagnostics', 'tasg-abo' ),
		);
	}

	/**
	 * The tab bar of WooCommerce > Settings, with our tab marked, so the hub looks like a settings tab.
	 */
	private function render_woocommerce_tabs(): void {
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::get_settings_pages();
		}

		$tabs = (array) apply_filters( 'woocommerce_settings_tabs_array', array() );

		echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . $key ) ) . '" class="nav-tab' . ( self::TAB === $key ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'settings';
		$current = isset( $this->sections[ $current ] ) ? $current : 'settings';
		$labels  = $this->labels();

		echo '<div class="wrap woocommerce"><h1 class="screen-reader-text">' . esc_html__( 'Subscriptions', 'tasg-abo' ) . '</h1>';

		$this->render_woocommerce_tabs();

		$links = array();
		foreach ( array_keys( $this->sections ) as $key ) {
			$links[] = '<li><a href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $current ? ' class="current"' : '' ) . '>' . esc_html( $labels[ $key ] ?? $key ) . '</a>';
		}
		echo '<ul class="subsubsub">' . implode( ' | </li>', $links ) . '</li></ul><br class="clear">';

		$this->sections[ $current ]->render();

		echo '</div>';
	}
}
