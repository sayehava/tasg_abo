<?php
/**
 * The page where customers cancel. The dashboard button, the link in the reminder email,
 * the shortcode button and any link or menu entry the owner makes all lead here.
 *
 * Shortcodes: [tasg_abo_cancel] is the form (put it on the page),
 *             [tasg_abo_cancel_button] is a button that links to the page.
 * Short link: /?tasg-abo-cancel=1 always opens the page, even if the page is replaced.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Cancellation;

use TasgAbo\Settings;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class CancelPage {

	public const QUERY = 'tasg-abo-cancel';

	public function register(): void {
		add_shortcode( 'tasg_abo_cancel', array( $this, 'render_form' ) );
		add_shortcode( 'tasg_abo_cancel_button', array( $this, 'render_button' ) );
		add_action( 'template_redirect', array( $this, 'short_link' ), 0 );
		add_action( 'template_redirect', array( $this, 'no_cache' ), 1 );
		add_action( 'admin_init', array( $this, 'ensure_page' ) );
	}

	/**
	 * Address of the page, or an empty string while there is none. With a subscription the
	 * link carries its secret key, so the customer needs neither login nor questions.
	 */
	public static function url( ?object $sub = null ): string {
		$id = Settings::cancel_page_id();

		if ( $id < 1 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}

		// With WPML the visitor gets the page in the current language, if it was translated.
		$id  = (int) apply_filters( 'wpml_object_id', $id, 'page', true );
		$url = (string) get_permalink( $id );

		return $sub ? add_query_arg( 'tasg_abo_key', $sub->cancel_key, $url ) : $url;
	}

	/**
	 * The link to use in menus, buttons and texts. It keeps working if the page changes.
	 */
	public static function short_url(): string {
		return add_query_arg( self::QUERY, '1', home_url( '/' ) );
	}

	public function short_link(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::QUERY ] ) ) {
			return;
		}

		$url = self::url();

		if ( '' !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * The page shows customer data and takes input, it must never come from a page cache.
	 */
	public function no_cache(): void {
		$id = Settings::cancel_page_id();

		if ( $id > 0 && is_page( $id ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/**
	 * Creates the page once, with the form on it, unless the owner already chose one.
	 */
	public function ensure_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->assign_language( Settings::cancel_page_id() );

		if ( get_option( 'tasg_abo_cancel_page_made' ) ) {
			return;
		}

		update_option( 'tasg_abo_cancel_page_made', '1', false );

		if ( Settings::cancel_page_id() > 0 && 'publish' === get_post_status( Settings::cancel_page_id() ) ) {
			return;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Cancel contracts', 'tasg-abo' ),
				'post_content' => '[tasg_abo_cancel]',
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
			update_option( Settings::CANCEL_PAGE, (int) $id );
			$this->assign_language( (int) $id );
		}
	}

	/**
	 * WPML hides and redirects a page that has no language. A page made by code gets the
	 * default language here, otherwise visitors would land on the home page.
	 */
	private function assign_language( int $id ): void {
		if ( $id < 1 || ! has_filter( 'wpml_default_language' ) ) {
			return;
		}

		$details = apply_filters( 'wpml_post_language_details', null, $id );

		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return;
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $id,
				'element_type'         => 'post_page',
				'trid'                 => false,
				'language_code'        => apply_filters( 'wpml_default_language', null ),
				'source_language_code' => null,
			)
		);
	}

	public function render_form(): string {
		return ( new CancelForm( new Repository() ) )->render();
	}

	/**
	 * A button linking to the page. The text is the one from the settings, or label="...".
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_button( $atts ): string {
		$atts = shortcode_atts( array( 'label' => '' ), (array) $atts, 'tasg_abo_cancel_button' );
		$url  = self::url();

		if ( '' === $url ) {
			return '';
		}

		$label = '' !== $atts['label'] ? $atts['label'] : Settings::cancel_button_text();

		return '<a class="button tasg-abo-cancel-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
}
