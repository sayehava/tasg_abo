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

		get_header();

		echo '<main class="tasg-abo-page woocommerce"><div class="tasg-abo-wrap">';
		echo '<h1 class="tasg-abo-title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $lead ) {
			echo '<p class="tasg-abo-lead">' . esc_html( $lead ) . '</p>';
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></main>';

		get_footer();
		exit;
	}
}
