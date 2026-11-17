<?php
/**
 * Shows the customer pages of the subscription links inside the shop theme:
 * the theme's header and footer (also an Elementor theme header), WooCommerce
 * button and form styles, and a small stylesheet for the layout.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Pricing;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Address;

defined( 'ABSPATH' ) || exit;

final class ThemePage {

	/**
	 * Print a full page and stop. Every dynamic value in $html is escaped where it
	 * is built and the form markup must stay intact, so it is not run through wp_kses.
	 */
	public static function render( string $title, string $html, string $lead = '' ): void {
		status_header( 200 );
		nocache_headers();

		// Personal links: keep them out of search engines.
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter(
			'pre_get_document_title',
			static function () use ( $title ) {
				return $title . ' - ' . get_bloginfo( 'name' );
			}
		);
		add_filter(
			'body_class',
			static function ( $classes ) {
				$classes[] = 'woocommerce';
				$classes[] = 'woocommerce-page';
				$classes[] = 'tasg-abo-body';
				return $classes;
			}
		);
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'tasg-abo-pages', TASG_ABO_URL . 'assets/css/pages.css', array(), TASG_ABO_VERSION );
			},
			20
		);

		self::as_normal_page();

		get_header();

		echo '<main class="tasg-abo-page woocommerce"><div class="tasg-abo-wrap">';
		echo '<h1 class="tasg-abo-title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $lead ) {
			echo '<p class="tasg-abo-lead">' . esc_html( $lead ) . '</p>';
		}

		// WooCommerce messages, for example a failed login on the payment page.
		if ( function_exists( 'wc_print_notices' ) && WC()->session ) {
			wc_print_notices();
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></main>';

		get_footer();
		exit;
	}

	/**
	 * The links point to the home page, so the theme would draw its front page header
	 * (a transparent header over the hero image). Showing the page as the cancel page,
	 * a normal page of the shop, makes the theme use its normal header and layout.
	 */
	private static function as_normal_page(): void {
		$page_id = (int) apply_filters( 'wpml_object_id', \TasgAbo\Settings::cancel_page_id(), 'page', true );

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return;
		}

		global $wp_query, $wp_the_query, $post;

		$wp_query     = new \WP_Query( array( 'page_id' => $page_id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post         = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
	}

	/**
	 * Card with the product, the date and amount of the next order and where it goes.
	 */
	public static function summary( object $sub, bool $with_address = true ): string {
		$product = wc_get_product( (int) $sub->product_id );

		if ( ! $product ) {
			return '';
		}

		$amount = wc_price( (float) Pricing::subscriber_price( $product, (int) $sub->plan_id ) * (int) $sub->quantity );
		$rows   = array();

		if ( in_array( $sub->status, array( 'active', 'paused' ), true ) ) {
			$next   = 'paused' === $sub->status && ! empty( $sub->pause_until ) ? strtotime( $sub->pause_until . ' UTC' ) : Actions::next_order( $sub );
			$rows[] = array( __( 'Next order', 'tasg-abo' ), esc_html( self::date( $next ) ) );
		}

		$rows[] = array( __( 'Amount', 'tasg-abo' ), wp_kses_post( $amount ) );

		if ( $with_address ) {
			$rows[] = array( __( 'Shipping to', 'tasg-abo' ), nl2br( esc_html( Address::formatted( Address::shipping( $sub ) ) ) ) );
		}

		$html = '<div class="tasg-abo-card tasg-abo-summary">' . $product->get_image( 'thumbnail' ) . '<div>';
		$html .= '<p class="tasg-abo-product">' . esc_html( $product->get_name() ) . '</p><dl>';

		foreach ( $rows as $row ) {
			$html .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . $row[1] . '</dd>';
		}

		return $html . '</dl></div></div>';
	}

	public static function date( int $timestamp ): string {
		return wp_date( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Secondary link next to the main button: back without changing anything.
	 */
	public static function back_link(): string {
		$url = is_user_logged_in() ? wc_get_account_endpoint_url( 'dashboard' ) : home_url( '/' );

		return '<a class="tasg-abo-back" href="' . esc_url( $url ) . '">' . esc_html__( 'Change nothing', 'tasg-abo' ) . '</a>';
	}
}
