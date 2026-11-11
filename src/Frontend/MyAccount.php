<?php
/**
 * "Subscriptions" tab in the customer's My Account area.
 *
 * Buttons reuse the email links, so the confirmation step is the same.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Subscriptions\Address;
use TasgAbo\Subscriptions\Links;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class MyAccount {

	private const ENDPOINT = 'abo';

	public function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
	}

	public function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	public function add_menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );

		$items[ self::ENDPOINT ] = __( 'Subscriptions', 'tasg-abo' );

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * The new endpoint needs the permalink rules refreshed once.
	 */
	public function maybe_flush_rewrites(): void {
		if ( get_option( 'tasg_abo_rewrites_flushed' ) !== TASG_ABO_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'tasg_abo_rewrites_flushed', TASG_ABO_VERSION );
		}
	}

	private function link( object $sub, string $action, string $label ): string {
		return '<a class="button" href="' . esc_url( Links::url( $sub, $action ) ) . '">' . esc_html( $label ) . '</a> ';
	}

	public function render(): void {
		$rows = ( new Repository() )->for_user( get_current_user_id() );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'You have no subscriptions yet.', 'tasg-abo' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr>';
		foreach ( array( __( 'Product', 'tasg-abo' ), __( 'Every', 'tasg-abo' ), __( 'Status', 'tasg-abo' ), __( 'Next order', 'tasg-abo' ), __( 'Ships to', 'tasg-abo' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$names = array(
			'active'    => __( 'Active', 'tasg-abo' ),
			'paused'    => __( 'Paused', 'tasg-abo' ),
			'cancelled' => __( 'Cancelled', 'tasg-abo' ),
		);

		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row->product_id );

			echo '<tr><td>' . esc_html( ( $product ? $product->get_name() : '' ) . ' x ' . (int) $row->quantity ) . '</td>';
			echo '<td>' . esc_html( \TasgAbo\Plans\Period::row_label( $row ) ) . '</td>';
			echo '<td>' . esc_html( $names[ $row->status ] ?? $row->status ) . '</td>';
			echo '<td>' . ( 'cancelled' === $row->status ? '' : esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->next_payment . ' UTC' ) ) ) ) . '</td>';

			// The address the next order will use, from the last order or a correction.
			echo '<td>' . ( 'cancelled' === $row->status ? '' : nl2br( esc_html( Address::formatted( Address::shipping( $row ) ) ) ) ) . '</td><td>';

			// Buttons are built from escaped parts in link().
			if ( 'active' === $row->status ) {
				echo $this->link( $row, 'skip', __( 'Skip next', 'tasg-abo' ) ) . $this->link( $row, 'pause', __( 'Pause', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'paused' === $row->status ) {
				echo $this->link( $row, 'continue', __( 'Continue', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( in_array( $row->status, array( 'active', 'paused' ), true ) ) {
				echo $this->link( $row, 'address', __( 'Change address', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->link( $row, 'cancel', __( 'Cancel', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
