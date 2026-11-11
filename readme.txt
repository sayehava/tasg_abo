=== Tasg Abo ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Subscriptions for WooCommerce simple products, charged through the saved Stripe payment method (PayPal is experimental).

== Description ==

Customers tick "Subscribe and save" on a product page and choose an interval. The plugin then creates a standard WooCommerce order on schedule and charges the saved Stripe payment method until the customer cancels.

* Email design is your choice (WooCommerce > Subscription settings > Email design): YayMail, the built-in editor, or automatic (YayMail if installed). With YayMail the emails appear under YayMail > Email templates with their own shortcodes.
* WPML: the subscribe settings are copied to product translations (wpml-config.xml), plan names can be translated in WPML > String Translation (context "Tasg Abo"), the customer's language is taken at checkout and emails are sent in that language, with texts per language in the email editor.
* Plans: define periods (minutes up to months) under WooCommerce > Subscription plans, each with its own reminder time. Mark short test plans as visible to shop managers only.
* Per product: enable, choose one or more plans, percent or fixed subscriber discount, with an optional discount per plan.
* A reminder email goes out before every charge, as long before as the plan says. Nothing is charged without it. It contains links to skip, pause or cancel without logging in.
* Pause covers the next order. Afterwards the customer is asked up to three times to continue or cancel, then the subscription is cancelled.
* Renewal orders are normal WooCommerce orders. The status after payment is chosen in the settings, so invoice and shipping automations keep working.
* If a payment fails, no order is kept and the subscription is cancelled. The customer is informed.
* Address, shipping method and cost follow the last order of the subscription: the first renewal copies the first order, the second copies the first renewal, and so on. Free shipping can be granted per subscription or for all active subscriptions, with an optional end date.
* Customers manage their subscriptions under My Account > Subscriptions: see plan, next order, address and past orders, and cancel, skip, pause, continue, change the address, change the plan or choose another saved Stripe payment method. The shop owner decides in the settings which of these (except cancelling) customers may do. The reminder email only offers the options that are switched on.
* Activity log: every step the plugin takes is logged (ids and results only, no card, address or email data) and shown with a filter and a download button under WooCommerce > Subscription diagnostics. A fatal PHP error inside the plugin is recorded there too.
* Privacy: subscriptions are included in the WordPress personal data export and erase tools, and suggested privacy policy text is added. The plugin stores no card or bank data.
* An email warns customers whose saved Stripe card expires before the next order, so they can pick another card in time. It can be switched off and the number of days is set in the settings.
* The reminder email shows the address of the next order and a link to correct it before the order is placed.
* A subscription must be ordered on its own, for one piece: no other product can be in the cart.
* No card data is stored by the plugin. Payment references stay with the payment plugin on the orders.
* Emails are real WooCommerce emails. A built in editor (WooCommerce > Subscription emails) lets you write subject, heading and text per language. Logo and colours come from the WooCommerce email settings. They are sent in the language of the original order (WPML).
* Scheduling uses the Action Scheduler that ships with WooCommerce.

== Installation ==

1. Upload the plugin folder to wp-content/plugins and activate it.
2. Make sure WooCommerce and the WooCommerce Stripe Gateway are active and Stripe is configured. For PayPal also install WooCommerce PayPal Payments with saving of payment methods enabled, then switch PayPal on in the subscription settings.
3. Open WooCommerce > Subscription settings and choose the renewal order status.
4. Create your plans under WooCommerce > Subscription plans.
5. Edit a product and enable the subscription fields on the General tab, then tick the plans it offers.

== Changelog ==

= 0.1.0 =
* First version.
