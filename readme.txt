=== Tasg Abo ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Subscriptions for WooCommerce simple products, charged through the saved Stripe payment method.

== Description ==

Customers tick "Subscribe and save" on a product page and choose an interval. The plugin then creates a standard WooCommerce order on schedule and charges the saved Stripe payment method until the customer cancels.

* Per product: enable, intervals in weeks, percent or fixed subscriber discount.
* A reminder email goes out before every charge (at least 48 hours). It contains links to skip, pause or cancel without logging in.
* Pause covers the next order. Afterwards the customer is asked up to three times to continue or cancel, then the subscription is cancelled.
* Renewal orders are normal WooCommerce orders. The status after payment is chosen in the settings, so invoice and shipping automations keep working.
* If a payment fails, no order is kept and the subscription is cancelled. The customer is informed.
* Shipping is copied from the first order. Free shipping can be granted per subscription or for all active subscriptions, with an optional end date.
* Emails are real WooCommerce emails, editable under WooCommerce > Settings > Emails and designable with YayMail. They are sent in the language of the original order (WPML).
* Scheduling uses the Action Scheduler that ships with WooCommerce.

== Installation ==

1. Upload the plugin folder to wp-content/plugins and activate it.
2. Make sure WooCommerce and the WooCommerce Stripe Gateway are active and Stripe is configured.
3. Open WooCommerce > Subscription settings and choose the renewal order status.
4. Edit a product and enable the subscription fields on the General tab.

== Changelog ==

= 0.1.0 =
* First version.
