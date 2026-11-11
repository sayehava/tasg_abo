<?php
/**
 * WooCommerce > Subscription emails: edit subject, heading and body of each
 * subscription email, separately for every language. Logo, colours and footer
 * come from WooCommerce > Settings > Emails, so the look stays the same as
 * all other shop emails.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Emails\EmailTexts;
use TasgAbo\Emails\SubscriptionEmail;

defined( 'ABSPATH' ) || exit;

final class EmailDesigner {

	private const SLUG = 'tasg-abo-emails';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_tasg_abo_save_email', array( $this, 'handle_save' ) );
		add_action( 'admin_post_tasg_abo_test_email', array( $this, 'handle_test' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Subscription emails', 'tasg-abo' ),
			__( 'Subscription emails', 'tasg-abo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @return SubscriptionEmail[] keyed by email id
	 */
	private function emails(): array {
		$found = array();

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof SubscriptionEmail ) {
				$found[ $email->id ] = $email;
			}
		}

		return $found;
	}

	private function page_url( string $email, string $lang ): string {
		return add_query_arg(
			array(
				'page'  => self::SLUG,
				'email' => $email,
				'lang'  => $lang,
			),
			admin_url( 'admin.php' )
		);
	}

	public function handle_save(): void {
		check_admin_referer( 'tasg_abo_save_email' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		$emails = $this->emails();
		$email  = isset( $_POST['email'] ) ? sanitize_key( wp_unslash( $_POST['email'] ) ) : '';
		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		if ( isset( $emails[ $email ] ) && isset( EmailTexts::languages()[ $lang ] ) ) {
			EmailTexts::save(
				$email,
				$lang,
				array(
					'subject' => isset( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '',
					'heading' => isset( $_POST['heading'] ) ? wp_unslash( $_POST['heading'] ) : '',
					'body'    => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '',
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'saved', '1', $this->page_url( $email, $lang ) ) );
		exit;
	}

	/**
	 * Send the email with sample data to the logged in admin.
	 */
	public function handle_test(): void {
		check_admin_referer( 'tasg_abo_test_email' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		$emails = $this->emails();
		$email  = isset( $_POST['email'] ) ? sanitize_key( wp_unslash( $_POST['email'] ) ) : '';
		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$user   = wp_get_current_user();
		$sent   = false;

		if ( isset( $emails[ $email ] ) && isset( EmailTexts::languages()[ $lang ] ) ) {
			$products = wc_get_products(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => '_tasg_abo_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$products = $products ? $products : wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) );

			$sample = (object) array(
				'id'               => 0,
				'user_id'          => $user->ID,
				'product_id'       => $products ? (int) $products[0] : 0,
				'quantity'         => 1,
				'interval_weeks'   => 4,
				'status'           => 'active',
				'next_payment'     => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ),
				'origin_order_id'  => 0,
				'last_order_id'    => 0,
				'language'         => 'default' === $lang ? '' : $lang,
				'address_override' => wp_json_encode(
					array(
						'first_name' => 'Sample',
						'last_name'  => 'Customer',
						'address_1'  => 'Sample street 1',
						'postcode'   => '12345',
						'city'       => 'Sample city',
						'country'    => (string) WC()->countries->get_base_country(),
					)
				),
				'cancel_key'       => 'sample-link-does-nothing',
			);

			$sent = $emails[ $email ]->deliver( $sample, $user->user_email );
		}

		wp_safe_redirect( add_query_arg( 'test', $sent ? '1' : '0', $this->page_url( $email, $lang ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$emails    = $this->emails();
		$languages = EmailTexts::languages();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$email_id = isset( $_GET['email'] ) ? sanitize_key( wp_unslash( $_GET['email'] ) ) : (string) key( $emails );
		$lang     = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : (string) key( $languages );
		$saved    = isset( $_GET['saved'] );
		$test     = isset( $_GET['test'] ) ? sanitize_key( wp_unslash( $_GET['test'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $emails[ $email_id ] ) || ! isset( $languages[ $lang ] ) ) {
			return;
		}

		$email = $emails[ $email_id ];
		$texts = EmailTexts::get( $email_id, $lang );
		$body  = '' !== $texts['body'] ? $texts['body'] : wpautop( esc_html( $email::default_body() ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscription emails', 'tasg-abo' ) . '</h1>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'tasg-abo' ) . '</p></div>';
		}

		if ( '1' === $test ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent to your address.', 'tasg-abo' ) . '</p></div>';
		} elseif ( '0' === $test ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The test email could not be sent. Check your mail setup.', 'tasg-abo' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $emails as $id => $item ) {
			echo '<a class="nav-tab' . ( $id === $email_id ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->page_url( $id, $lang ) ) . '">' . esc_html( $item->get_title() ) . '</a>';
		}
		echo '</h2>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $languages as $code => $name ) {
			echo '<a class="nav-tab' . ( $code === $lang ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->page_url( $email_id, $code ) ) . '">' . esc_html( $name ) . '</a>';
		}
		echo '</h2>';

		echo '<p>' . esc_html( $email->get_description() ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Placeholders:', 'tasg-abo' ) . '</strong> {customer_name} {product_name} {next_date} {amount} {shipping_address} {address_url} {skip_url} {pause_url} {cancel_url} {continue_url}</p>';
		echo '<p class="description">' . esc_html__( 'Leave subject or heading empty to use the default. Logo, colours and footer are set in WooCommerce > Settings > Emails.', 'tasg-abo' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tasg_abo_save_email' );
		echo '<input type="hidden" name="action" value="tasg_abo_save_email">';
		echo '<input type="hidden" name="email" value="' . esc_attr( $email_id ) . '">';
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="tasg_subject">' . esc_html__( 'Subject', 'tasg-abo' ) . '</label></th><td><input class="large-text" id="tasg_subject" name="subject" value="' . esc_attr( $texts['subject'] ) . '" placeholder="' . esc_attr( $email->get_default_subject() ) . '"></td></tr>';
		echo '<tr><th><label for="tasg_heading">' . esc_html__( 'Heading', 'tasg-abo' ) . '</label></th><td><input class="large-text" id="tasg_heading" name="heading" value="' . esc_attr( $texts['heading'] ) . '" placeholder="' . esc_attr( $email->get_default_heading() ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Text', 'tasg-abo' ) . '</th><td>';
		wp_editor( $body, 'tasg_body', array( 'textarea_name' => 'body', 'textarea_rows' => 14 ) );
		echo '</td></tr></table>';

		submit_button();
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tasg_abo_test_email' );
		echo '<input type="hidden" name="action" value="tasg_abo_test_email">';
		echo '<input type="hidden" name="email" value="' . esc_attr( $email_id ) . '">';
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		echo '<p class="description">' . esc_html__( 'Sends the saved version with sample data to your own email address. Save first.', 'tasg-abo' ) . '</p>';
		echo '<button class="button">' . esc_html__( 'Send test email to me', 'tasg-abo' ) . '</button></form></div>';
	}
}
