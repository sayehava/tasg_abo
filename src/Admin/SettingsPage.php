<?php
/**
 * Settings section: WooCommerce > Settings > Subscriptions > Settings.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Gateways\StripeMethods;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const SLUG = 'tasg-abo-settings';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// options.php asks for manage_options by default. Shop managers may save these settings too.
		add_filter(
			'option_page_capability_' . self::SLUG,
			static function () {
				return 'manage_woocommerce';
			}
		);
	}

	public function register_settings(): void {
		register_setting( self::SLUG, Settings::RENEWAL_STATUS, array( 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( self::SLUG, Settings::PAUSE_REMINDER_DAYS, array( 'sanitize_callback' => array( $this, 'sanitize_days' ) ) );
		foreach ( Settings::CUSTOMER_OPTIONS as $definition ) {
			register_setting( self::SLUG, $definition[0], array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		}

		register_setting( self::SLUG, Settings::EMAIL_DESIGNER, array( 'sanitize_callback' => array( $this, 'sanitize_designer' ) ) );
		register_setting( self::SLUG, Settings::BOX_TEXT, array( 'sanitize_callback' => 'wp_kses_post' ) );
		register_setting( self::SLUG, Settings::CART_CONFLICT, array( 'sanitize_callback' => array( $this, 'sanitize_cart_conflict' ) ) );
		register_setting( self::SLUG, Settings::TEST_MARKER_TEXT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::SLUG, Settings::TEST_MARKER_NOTE, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( self::SLUG, Settings::CARD_NOTICE, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( self::SLUG, Settings::CARD_NOTICE_DAYS, array( 'sanitize_callback' => array( $this, 'sanitize_notice_days' ) ) );
		register_setting( self::SLUG, Settings::CANCEL_BUTTON, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::SLUG, Settings::CANCEL_PAGE, array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::SLUG, Settings::CANCEL_FIELDS, array( 'sanitize_callback' => array( $this, 'sanitize_methods' ) ) );
		register_setting( self::SLUG, Settings::ADMIN_ONLY, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( self::SLUG, Settings::STRIPE_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( self::SLUG, Settings::STRIPE_METHODS, array( 'sanitize_callback' => array( $this, 'sanitize_methods' ) ) );
		register_setting( self::SLUG, Settings::PAYPAL_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
	}

	/**
	 * The cancel page: its button text, the page itself, the links to it and the questions.
	 */
	private function render_cancel_rows(): void {
		echo '<tr><th scope="row"><label for="tasg_cancel_button">' . esc_html__( 'Cancel button text', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_cancel_button" class="regular-text" name="' . esc_attr( Settings::CANCEL_BUTTON ) . '" value="' . esc_attr( Settings::cancel_button_raw() ) . '" placeholder="' . esc_attr__( 'Cancel contracts here', 'tasg-abo' ) . '">';
		echo '<p class="description">' . esc_html__( 'Text of the cancel button in My Account and of the button shortcode. German law asks for "Verträge hier kündigen" or an equally clear wording. Leave empty for the default. With WPML, translate it in WPML > String Translation (context Tasg Abo).', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_cancel_page">' . esc_html__( 'Cancel page', 'tasg-abo' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => Settings::CANCEL_PAGE,
				'id'                => 'tasg_cancel_page',
				'selected'          => Settings::cancel_page_id(),
				'show_option_none'  => __( '(none)', 'tasg-abo' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'The page with the shortcode [tasg_abo_cancel]. It was created for you. My Account, the reminder email and every cancel link lead here.', 'tasg-abo' ) . '</p>';
		if ( did_action( 'elementor/loaded' ) ) {
			echo '<p class="description">' . esc_html__( 'Designing the page with Elementor? Add the widgets "Cancellation form" and "Cancel contracts button" (category Tasg Abo) to a page, then select that page here.', 'tasg-abo' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Use this link anywhere, for example in the footer or a menu, without a shortcode:', 'tasg-abo' ) . ' <code>' . esc_html( \TasgAbo\Cancellation\CancelPage::short_url() ) . '</code><br>';
		echo esc_html__( 'Or a button with the shortcode:', 'tasg-abo' ) . ' <code>[tasg_abo_cancel_button]</code> <code>[tasg_abo_cancel_button label="Cancel here"]</code></p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Questions without login', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::CANCEL_FIELDS ) . '[]" value="">';
		foreach ( Settings::cancel_field_choices() as $key => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( Settings::CANCEL_FIELDS ) . '[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, Settings::cancel_fields(), true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'A customer who is not logged in enters the order number and answers these questions. The answers must match the billing data of that order. Ask for at least one, otherwise the order number alone is enough to cancel.', 'tasg-abo' ) . '</p></td></tr>';
	}

	public function sanitize_yes_no( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * @return string[] Stripe method types, without empty entries.
	 */
	public function sanitize_methods( $value ): array {
		return array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
	}

	public function sanitize_cart_conflict( $value ): string {
		return 'block' === $value ? 'block' : 'replace';
	}

	public function sanitize_designer( $value ): string {
		return in_array( $value, array( 'auto', 'yaymail', 'plugin' ), true ) ? (string) $value : 'auto';
	}

	public function sanitize_notice_days( $value ): int {
		return max( 2, (int) $value );
	}

	public function sanitize_days( $value ): int {
		return max( 1, (int) $value );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="tasg-abo-section">';
		echo '<form method="post" action="options.php">';
		settings_fields( self::SLUG );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Test phase', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::ADMIN_ONLY ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::ADMIN_ONLY ) . '" value="yes"' . checked( Settings::admin_only(), true, false ) . '> ' . esc_html__( 'Only shop managers can see and use subscriptions', 'tasg-abo' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Keep this on until you tested a full cycle yourself. Customers then see nothing. Untick it to open subscriptions to everyone.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_status">' . esc_html__( 'Status of renewal orders after payment', 'tasg-abo' ) . '</label></th><td>';
		echo '<select id="tasg_status" name="' . esc_attr( Settings::RENEWAL_STATUS ) . '">';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( Settings::renewal_status(), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Choose the status your invoice, delivery note and label automation reacts to.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_days">' . esc_html__( 'Days between pause reminders', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_days" type="number" min="1" name="' . esc_attr( Settings::PAUSE_REMINDER_DAYS ) . '" value="' . esc_attr( (string) Settings::pause_reminder_days() ) . '">';
		echo '<p class="description">' . esc_html__( 'A paused customer gets three reminders to continue or cancel. Without an answer the subscription is cancelled.', 'tasg-abo' ) . '</p></td></tr>';

		$choices = array(
			'skip'    => __( 'Skip the next order', 'tasg-abo' ),
			'pause'   => __( 'Pause for the next period', 'tasg-abo' ),
			'address' => __( 'Change the shipping address of the next order', 'tasg-abo' ),
			'plan'    => __( 'Change the plan (period)', 'tasg-abo' ),
			'payment' => __( 'Change the payment method (Stripe)', 'tasg-abo' ),
			'orders'  => __( 'See the past orders of the subscription', 'tasg-abo' ),
		);

		$designers = array(
			'auto'    => __( 'Automatic: YayMail if it is installed, otherwise the plugin editor', 'tasg-abo' ),
			'yaymail' => __( 'YayMail', 'tasg-abo' ),
			'plugin'  => __( 'The plugin editor', 'tasg-abo' ),
		);

		echo '<tr><th scope="row"><label for="tasg_designer">' . esc_html__( 'Email design', 'tasg-abo' ) . '</label></th><td>';
		echo '<select id="tasg_designer" name="' . esc_attr( Settings::EMAIL_DESIGNER ) . '">';
		foreach ( $designers as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( Settings::email_designer(), $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html( Settings::yaymail_installed() ? __( 'YayMail was found on this site.', 'tasg-abo' ) : __( 'YayMail was not found on this site, so the plugin editor is used.', 'tasg-abo' ) ) . ' ';
		echo esc_html__( 'Design the subscription emails in YayMail (YayMail > Email templates) or in the plugin editor (WooCommerce > Settings > Subscriptions > Emails).', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_boxtext">' . esc_html__( 'Text under the subscribe box', 'tasg-abo' ) . '</label></th><td>';
		echo '<textarea id="tasg_boxtext" class="large-text" rows="3" name="' . esc_attr( Settings::BOX_TEXT ) . '">' . esc_textarea( Settings::box_text_raw() ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown under the checkbox on the product page and follows the chosen plan. Leave empty for the default text. Placeholders, calculated from the actual price: {price} {regular_price} {saving} {saving_percent} {plan} {period}. The same values work as shortcodes: [tasg_abo_price] [tasg_abo_regular_price] [tasg_abo_saving] [tasg_abo_saving_percent] [tasg_abo_plan] [tasg_abo_period]. Each product can have its own text on its product page. With WPML, translate this text in WPML > String Translation (context Tasg Abo).', 'tasg-abo' ) . '</p></td></tr>';

		$conflicts = array(
			'replace' => __( 'Arrange the cart automatically: the newest choice wins', 'tasg-abo' ),
			'block'   => __( 'Show a message and keep the cart as it is', 'tasg-abo' ),
		);

		echo '<tr><th scope="row"><label for="tasg_conflict">' . esc_html__( 'Subscription and other products', 'tasg-abo' ) . '</label></th><td>';
		echo '<select id="tasg_conflict" name="' . esc_attr( Settings::CART_CONFLICT ) . '">';
		foreach ( $conflicts as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( Settings::cart_conflict(), $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'A subscription must be ordered on its own. When a customer adds a subscription, the other products leave the cart. When a customer adds another product while a subscription is in the cart, the subscription leaves the cart. The customer sees a message each time.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="tasg_marker">' . esc_html__( 'Test order marker', 'tasg-abo' ) . '</label></th><td>';
		echo '<input id="tasg_marker" class="large-text" name="' . esc_attr( Settings::TEST_MARKER_TEXT ) . '" value="' . esc_attr( Settings::test_marker_text() ) . '"><br>';
		echo '<input type="hidden" name="' . esc_attr( Settings::TEST_MARKER_NOTE ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::TEST_MARKER_NOTE ) . '" value="yes"' . checked( Settings::test_marker_in_note(), true, false ) . '> ' . esc_html__( 'Also add this text to the customer note of test orders', 'tasg-abo' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Orders from a subscription made during the test phase or from a test plan are marked: order meta _tasg_abo_test = yes, a private order note and, if ticked, this text in the customer note, so an invoice that prints the note shows it.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Expiring card warning', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::CARD_NOTICE ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::CARD_NOTICE ) . '" value="yes"' . checked( Settings::card_notice(), true, false ) . '> ' . esc_html__( 'Email customers whose saved card expires before the next order', 'tasg-abo' ) . '</label><br>';
		echo '<label>' . esc_html__( 'Check this many days before the order:', 'tasg-abo' ) . ' <input type="number" min="2" style="width:5em" name="' . esc_attr( Settings::CARD_NOTICE_DAYS ) . '" value="' . esc_attr( (string) Settings::card_notice_days() ) . '"></label>';
		echo '<p class="description">' . esc_html__( 'Only for Stripe cards, and only while customers may change their payment method (see below), because the email links to that page.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'What customers may do', 'tasg-abo' ) . '</th><td>';
		foreach ( $choices as $action => $label ) {
			$option = Settings::CUSTOMER_OPTIONS[ $action ][0];
			echo '<input type="hidden" name="' . esc_attr( $option ) . '" value="no">';
			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $option ) . '" value="yes"' . checked( Settings::customer_may( $action ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Cancelling is always possible for customers (contracts concluded online in Germany need an easy way to cancel). Continuing after a pause is always possible too.', 'tasg-abo' ) . '</p></td></tr>';

		$this->render_cancel_rows();

		echo '<tr><th scope="row">' . esc_html__( 'Stripe for subscriptions', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::STRIPE_ENABLED ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::STRIPE_ENABLED ) . '" value="yes"' . checked( Settings::stripe_enabled(), true, false ) . '> ' . esc_html__( 'Offer Stripe for subscriptions', 'tasg-abo' ) . '</label>';

		$selectable = StripeMethods::selectable();

		if ( $selectable ) {
			$allowed = StripeMethods::allowed();

			echo '<p style="margin:10px 0 4px"><strong>' . esc_html__( 'Methods offered for subscriptions', 'tasg-abo' ) . '</strong></p>';
			echo '<input type="hidden" name="' . esc_attr( Settings::STRIPE_METHODS ) . '[]" value="">';
			foreach ( $selectable as $id => $label ) {
				echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( Settings::STRIPE_METHODS ) . '[]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, $allowed, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
			}

			if ( StripeMethods::wallets() ) {
				echo '<p class="description">' . esc_html__( 'Apple Pay and Google Pay are active in Stripe and work as part of Card.', 'tasg-abo' ) . '</p>';
			}

			$others = StripeMethods::not_reusable();
			if ( $others ) {
				echo '<p class="description">' . esc_html__( 'Active in Stripe but not offered for subscriptions, because they cannot be charged again later:', 'tasg-abo' ) . ' ' . esc_html( implode( ', ', $others ) ) . '</p>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'No active Stripe payment methods were found.', 'tasg-abo' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'Only methods that are switched on in your Stripe settings and that can be charged again later are listed. Apple Pay and Google Pay come with Card. Link cannot be switched off here. SEPA direct debit appears only if you activated it in Stripe.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'PayPal (experimental)', 'tasg-abo' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( Settings::PAYPAL_ENABLED ) . '" value="no">';
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::PAYPAL_ENABLED ) . '" value="yes"' . checked( Settings::paypal_enabled(), true, false ) . '> ' . esc_html__( 'Offer PayPal for subscriptions', 'tasg-abo' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Needs the WooCommerce PayPal Payments plugin with saving of PayPal payment methods enabled, and the customer must save the method at checkout. Without a saved method the subscription is not created. Try it with a test purchase first.', 'tasg-abo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'PayPal also needs the feature "Save PayPal and Venmo" on your PayPal account: WooCommerce > Settings > Payments > PayPal > Overview > Features > Sign up. Without it PayPal cannot be charged again.', 'tasg-abo' ) . '</p></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
