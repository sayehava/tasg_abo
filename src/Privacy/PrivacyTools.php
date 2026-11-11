<?php
/**
 * Hooks into the WordPress privacy tools (Tools > Export / Erase Personal Data).
 *
 * Export: the subscriptions of a customer, without the secret key of the email links.
 * Erase: active and paused subscriptions are cancelled and the personal data held in
 * the subscription is cleared. The bare record stays, because orders and the
 * accounting that depends on them have to be kept, and the admin is told so.
 *
 * No card or bank data is stored by this plugin, so there is none to export or erase.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Privacy;

use TasgAbo\Plans\Period;
use TasgAbo\Subscriptions\Actions;
use TasgAbo\Subscriptions\Address;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class PrivacyTools {

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	public function add_exporter( array $exporters ): array {
		$exporters['tasg-abo'] = array(
			'exporter_friendly_name' => __( 'Subscriptions', 'tasg-abo' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	public function add_eraser( array $erasers ): array {
		$erasers['tasg-abo'] = array(
			'eraser_friendly_name' => __( 'Subscriptions', 'tasg-abo' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @param string $email_address Email address of the person.
	 */
	public function export( $email_address, $page = 1 ): array {
		$user  = get_user_by( 'email', (string) $email_address );
		$items = array();

		if ( $user ) {
			foreach ( ( new Repository() )->for_user( (int) $user->ID ) as $row ) {
				$product = wc_get_product( (int) $row->product_id );
				$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

				$data = array(
					array( 'name' => __( 'Product', 'tasg-abo' ), 'value' => $product ? $product->get_name() : '' ),
					array( 'name' => __( 'Quantity', 'tasg-abo' ), 'value' => (string) (int) $row->quantity ),
					array( 'name' => __( 'Plan', 'tasg-abo' ), 'value' => Period::row_label( $row ) ),
					array( 'name' => __( 'Status', 'tasg-abo' ), 'value' => (string) $row->status ),
					array( 'name' => __( 'Started', 'tasg-abo' ), 'value' => wp_date( $format, strtotime( $row->created . ' UTC' ) ) ),
					array( 'name' => __( 'Next order', 'tasg-abo' ), 'value' => $row->next_payment ? wp_date( $format, strtotime( $row->next_payment . ' UTC' ) ) : '' ),
					array( 'name' => __( 'Language', 'tasg-abo' ), 'value' => (string) $row->language ),
					array( 'name' => __( 'Payment gateway', 'tasg-abo' ), 'value' => (string) $row->gateway ),
				);

				if ( ! empty( $row->address_override ) ) {
					$data[] = array(
						'name'  => __( 'Corrected shipping address for the next order', 'tasg-abo' ),
						'value' => Address::formatted( Address::shipping( $row ) ),
					);
				}

				$items[] = array(
					'group_id'          => 'tasg-abo-subscriptions',
					'group_label'       => __( 'Subscriptions', 'tasg-abo' ),
					'group_description' => __( 'Subscriptions of this customer. No card or bank data is stored by the shop.', 'tasg-abo' ),
					'item_id'           => 'tasg-abo-' . (int) $row->id,
					'data'              => $data,
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * @param string $email_address Email address of the person.
	 */
	public function erase( $email_address, $page = 1 ): array {
		$user     = get_user_by( 'email', (string) $email_address );
		$removed  = false;
		$retained = false;
		$messages = array();

		if ( $user ) {
			$repository = new Repository();
			$actions    = new Actions( $repository );

			foreach ( $repository->for_user( (int) $user->ID ) as $row ) {
				// Stops further charges and clears the address correction and payment link.
				$actions->cancel( $row );

				$repository->update(
					(int) $row->id,
					array(
						'address_override' => null,
						'payment_token_id' => 0,
					)
				);

				$removed  = true;
				$retained = true;
			}

			if ( $retained ) {
				$messages[] = __( 'Subscriptions were cancelled and their personal data cleared. The bare subscription records are kept, because the orders and accounting that depend on them must be kept.', 'tasg-abo' );
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the privacy policy page (Settings > Privacy).
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Subscriptions', 'tasg-abo' ),
			wp_kses_post(
				wpautop(
					__( 'When you subscribe to a product, we store the subscription (product, plan, dates and status), the language of your order and, if you correct it, a shipping address for your next order. We do not store card or bank details. Payments are handled by the payment provider (for example Stripe or PayPal), and we only keep references that the payment plugins already keep with your orders. Subscription records are kept as long as our accounting and legal duties require. On request we cancel your subscriptions and clear the personal data in them.', 'tasg-abo' )
				)
			)
		);
	}
}
