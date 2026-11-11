<?php
/**
 * Subscription reminder email (HTML).
 *
 * @package TasgAbo
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

// The body is plain text or the editor's HTML, cleaned to the same tags as post content.
echo wp_kses_post( wpautop( make_clickable( $body ) ) );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
