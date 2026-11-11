<?php
/**
 * Hands the rendering of a subscription email to its YayMail template.
 * Used by YayMail through TasgEmail::get_template_path().
 *
 * @package TasgAbo
 */

defined( 'ABSPATH' ) || exit;

$tasg_email    = isset( $email ) && is_object( $email ) ? $email : ( isset( $args['email'] ) ? $args['email'] : null );
$tasg_yaymail  = $tasg_email ? \TasgAbo\YayMail\TasgEmail::for_id( (string) $tasg_email->id ) : null;
$tasg_template = $tasg_yaymail ? $tasg_yaymail->template : null;

if ( ! empty( $tasg_template ) ) {
	$tasg_args = isset( $args ) && is_array( $args ) ? $args : get_defined_vars();
	yaymail_kses_post_e( $tasg_template->get_content( $tasg_args ) );
}
