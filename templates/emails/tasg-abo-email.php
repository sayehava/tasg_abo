<?php
/**
 * Subscription reminder email (HTML).
 *
 * @package TasgAbo
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo wp_kses_post( wpautop( make_clickable( esc_html( $body ) ) ) );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
