<?php
/**
 * Texts written in the plugin's own email editor, one set per email and language.
 * An empty field means: use the default from WooCommerce > Settings > Emails.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailTexts {

	private const OPTION = 'tasg_abo_email_texts';

	public static function get( string $email_id, string $lang ): array {
		$all = (array) get_option( self::OPTION, array() );

		return wp_parse_args(
			isset( $all[ $email_id ][ $lang ] ) ? (array) $all[ $email_id ][ $lang ] : array(),
			array(
				'subject' => '',
				'heading' => '',
				'body'    => '',
			)
		);
	}

	/**
	 * The language key texts are stored under. An order language that has no texts
	 * of its own (a locale like de_DE next to WPML codes like de, or no WPML at all)
	 * falls back to the two letter code, then to "default".
	 */
	public static function resolve_key( string $lang ): string {
		$known = self::languages();

		if ( isset( $known[ $lang ] ) ) {
			return $lang;
		}

		$short = strtolower( substr( $lang, 0, 2 ) );

		return isset( $known[ $short ] ) ? $short : 'default';
	}

	public static function save( string $email_id, string $lang, array $texts ): void {
		$all = (array) get_option( self::OPTION, array() );

		$all[ $email_id ][ $lang ] = array(
			'subject' => sanitize_text_field( $texts['subject'] ?? '' ),
			'heading' => sanitize_text_field( $texts['heading'] ?? '' ),
			'body'    => wp_kses_post( $texts['body'] ?? '' ),
		);

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Languages to write texts for: the active WPML languages, or one default.
	 *
	 * @return array<string,string> code => name
	 */
	public static function languages(): array {
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$list   = array();

		if ( is_array( $active ) ) {
			foreach ( $active as $code => $info ) {
				$list[ (string) $code ] = (string) ( $info['native_name'] ?? $code );
			}
		}

		return $list ? $list : array( 'default' => __( 'All languages', 'tasg-abo' ) );
	}
}
