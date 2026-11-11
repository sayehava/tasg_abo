<?php
/**
 * WooCommerce > Subscriptions: list, cancel/pause/continue and free shipping.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Repository;
use TasgAbo\Subscriptions\Schema;

defined( 'ABSPATH' ) || exit;

final class SubscriptionsPage {

	private const SLUG = 'tasg-abo-subscriptions';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_tasg_abo_change', array( $this, 'handle_change' ) );
		add_action( 'admin_post_tasg_abo_shipping', array( $this, 'handle_shipping' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Subscriptions', 'tasg-abo' ),
			__( 'Subscriptions', 'tasg-abo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function handle_change(): void {
		check_admin_referer( 'tasg_abo_change' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		$repository = new Repository();
		$sub        = $repository->find( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		$action     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( $sub && in_array( $action, array( 'cancel', 'pause', 'continue' ), true ) ) {
			( new Actions( $repository ) )->run( $sub, $action );
		}

		$this->back();
	}

	/**
	 * Set the shipping mode of one subscription, or of all active ones (id = all).
	 */
	public function handle_shipping(): void {
		check_admin_referer( 'tasg_abo_shipping' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		global $wpdb;

		$mode  = isset( $_POST['mode'] ) && 'free' === $_POST['mode'] ? 'free' : 'copy';
		$until = isset( $_POST['until'] ) ? sanitize_text_field( wp_unslash( $_POST['until'] ) ) : '';
		$ts    = '' !== $until ? strtotime( $until . ' 23:59:59' ) : false;
		$data  = array(
			'shipping_mode'  => $mode,
			'shipping_until' => $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null,
		);

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( 'all' === $id ) {
			$wpdb->update( Schema::table(), $data, array( 'status' => 'active' ) );
		} elseif ( absint( $id ) > 0 ) {
			( new Repository() )->update( absint( $id ), $data );
		}

		$this->back();
	}

	private function button( int $id, string $do, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'tasg_abo_change' );
		echo '<input type="hidden" name="action" value="tasg_abo_change"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<button class="button button-small" name="do" value="' . esc_attr( $do ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	private function shipping_form( string $id, string $mode, string $until, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tasg_abo_shipping' );
		echo '<input type="hidden" name="action" value="tasg_abo_shipping"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		echo '<select name="mode"><option value="copy"' . selected( $mode, 'copy', false ) . '>' . esc_html__( 'Copy from first order', 'tasg-abo' ) . '</option>';
		echo '<option value="free"' . selected( $mode, 'free', false ) . '>' . esc_html__( 'Free shipping', 'tasg-abo' ) . '</option></select> ';
		echo '<input type="date" name="until" value="' . esc_attr( $until ) . '" title="' . esc_attr__( 'Free shipping until (empty = no end)', 'tasg-abo' ) . '"> ';
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table() . ' ORDER BY id DESC LIMIT 500' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscriptions', 'tasg-abo' ) . '</h1>';

		echo '<h2>' . esc_html__( 'Shipping for all active subscriptions', 'tasg-abo' ) . '</h2>';
		$this->shipping_form( 'all', 'free', '', __( 'Apply to all active', 'tasg-abo' ) );

		echo '<table class="widefat striped" style="margin-top:1em"><thead><tr>';
		foreach ( array( 'ID', __( 'Customer', 'tasg-abo' ), __( 'Product', 'tasg-abo' ), __( 'Every', 'tasg-abo' ), __( 'Status', 'tasg-abo' ), __( 'Next payment (UTC)', 'tasg-abo' ), __( 'Shipping', 'tasg-abo' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user    = get_userdata( (int) $row->user_id );
			$product = wc_get_product( (int) $row->product_id );
			$until   = $row->shipping_until ? gmdate( 'Y-m-d', strtotime( $row->shipping_until . ' UTC' ) ) : '';

			echo '<tr><td>' . (int) $row->id . '</td>';
			echo '<td>' . esc_html( $user ? $user->user_email : '' ) . '</td>';
			echo '<td>' . esc_html( ( $product ? $product->get_name() : '' ) . ' x ' . (int) $row->quantity ) . '</td>';
			/* translators: %d: number of weeks */
			echo '<td>' . esc_html( sprintf( _n( '%d week', '%d weeks', (int) $row->interval_weeks, 'tasg-abo' ), (int) $row->interval_weeks ) ) . '</td>';
			echo '<td>' . esc_html( $row->status ) . '</td>';
			echo '<td>' . esc_html( (string) $row->next_payment ) . '</td><td>';
			$this->shipping_form( (string) (int) $row->id, (string) $row->shipping_mode, $until, __( 'Save', 'tasg-abo' ) );
			echo '</td><td>';

			if ( 'active' === $row->status ) {
				$this->button( (int) $row->id, 'pause', __( 'Pause', 'tasg-abo' ) );
			}
			if ( 'paused' === $row->status ) {
				$this->button( (int) $row->id, 'continue', __( 'Continue', 'tasg-abo' ) );
			}
			if ( in_array( $row->status, array( 'active', 'paused' ), true ) ) {
				$this->button( (int) $row->id, 'cancel', __( 'Cancel', 'tasg-abo' ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
