<?php
/**
 * "Subscriptions" tab in the customer's My Account area.
 *
 * Buttons reuse the email links, so the confirmation step is the same.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Pricing;
use TasgAbo\Settings;
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
			$status = $names[ $row->status ] ?? $row->status;

			if ( 'active' === $row->status && ! empty( $row->cancel_after ) ) {
				/* translators: %s: date the minimum term ends */
				$status = sprintf( __( 'Ends %s', 'tasg-abo' ), wp_date( get_option( 'date_format' ), strtotime( $row->cancel_after . ' UTC' ) ) );
			} elseif ( ! empty( $row->term_ends ) && in_array( $row->status, array( 'active', 'paused' ), true ) && strtotime( $row->term_ends . ' UTC' ) > time() ) {
				/* translators: 1: status, 2: date the minimum term ends */
				$status = sprintf( __( '%1$s (minimum term until %2$s)', 'tasg-abo' ), $status, wp_date( get_option( 'date_format' ), strtotime( $row->term_ends . ' UTC' ) ) );
			}

			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . ( 'cancelled' === $row->status ? '' : esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->next_payment . ' UTC' ) ) ) ) . '</td>';

			// The address the next order will use, from the last order or a correction.
			echo '<td>' . ( 'cancelled' === $row->status ? '' : nl2br( esc_html( Address::formatted( Address::shipping( $row ) ) ) ) ) . '</td><td>';

			// Buttons are built from escaped parts in link(). Each one follows the shop settings.
			$open = in_array( $row->status, array( 'active', 'paused' ), true );

			// Inside a minimum term skip and pause are not offered.
			$bound = ! empty( $row->term_ends ) && strtotime( $row->term_ends . ' UTC' ) > time();

			if ( 'active' === $row->status && ! $bound && Settings::customer_may( 'skip' ) ) {
				echo $this->link( $row, 'skip', __( 'Skip next', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'active' === $row->status && ! $bound && Settings::customer_may( 'pause' ) ) {
				echo $this->link( $row, 'pause', __( 'Pause', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'paused' === $row->status ) {
				echo $this->link( $row, 'continue', __( 'Continue', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $open && Settings::customer_may( 'address' ) ) {
				echo $this->link( $row, 'address', __( 'Change address', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $open && Settings::customer_may( 'plan' ) && $product && count( Pricing::plans( $product ) ) > 1 ) {
				echo $this->link( $row, 'plan', __( 'Change plan', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $open && Settings::customer_may( 'payment' ) && 0 === strpos( (string) $row->gateway, 'stripe' ) ) {
				echo $this->link( $row, 'payment', __( 'Payment method', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $open && ! empty( $row->cancel_after ) ) {
				echo $this->link( $row, 'keep', __( 'Keep subscription', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( $open ) {
				echo $this->link( $row, 'cancel', __( 'Cancel', 'tasg-abo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( Settings::customer_may( 'orders' ) ) {
			$this->render_orders( $rows );
		}
	}

	/**
	 * The first order and the renewal orders of each subscription, newest first.
	 *
	 * @param object[] $rows Subscriptions of the customer.
	 */
	private function render_orders( array $rows ): void {
		foreach ( $rows as $row ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => get_current_user_id(),
					'limit'       => 10,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_tasg_abo_renewal_of',
							'value' => (int) $row->id,
						),
					),
				)
			);

			$first = wc_get_order( (int) $row->origin_order_id );
			if ( $first ) {
				$orders[] = $first;
			}

			if ( ! $orders ) {
				continue;
			}

			$product = wc_get_product( (int) $row->product_id );

			/* translators: %s: product name */
			echo '<h3>' . esc_html( sprintf( __( 'Orders of the subscription for %s', 'tasg-abo' ), $product ? $product->get_name() : '' ) ) . '</h3><ul>';
			foreach ( $orders as $order ) {
				echo '<li><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> '
					. esc_html( wc_format_datetime( $order->get_date_created() ) . ', ' . wp_strip_all_tags( $order->get_formatted_order_total() ) . ', ' . wc_get_order_status_name( $order->get_status() ) )
					. '</li>';
			}
			echo '</ul>';
		}
	}
}
