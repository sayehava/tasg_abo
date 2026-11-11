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

* Per product: enable, intervals in weeks, percent or fixed subscriber discount.
* A reminder email goes out before every charge (at least 48 hours). It contains links to skip, pause or cancel without logging in.
* Pause covers the next order. Afterwards the customer is asked up to three times to continue or cancel, then the subscription is cancelled.
* Renewal orders are normal WooCommerce orders. The status after payment is chosen in the settings, so invoice and shipping automations keep working.
* If a payment fails, no order is kept and the subscription is cancelled. The customer is informed.
* Address, shipping method and cost follow the last order of the subscription: the first renewal copies the first order, the second copies the first renewal, and so on. Free shipping can be granted per subscription or for all active subscriptions, with an optional end date.
* The reminder email shows the address of the next order and a link to correct it before the order is placed.
* A subscription must be ordered on its own, for one piece: no other product can be in the cart.
* No card data is stored by the plugin. Payment references stay with the payment plugin on the orders.
* Emails are real WooCommerce emails. A built in editor (WooCommerce > Subscription emails) lets you write subject, heading and text per language. Logo and colours come from the WooCommerce email settings. They are sent in the language of the original order (WPML).
* Scheduling uses the Action Scheduler that ships with WooCommerce.

== Installation ==

1. Upload the plugin folder to wp-content/plugins and activate it.
2. Make sure WooCommerce and the WooCommerce Stripe Gateway are active and Stripe is configured. For PayPal also install WooCommerce PayPal Payments with saving of payment methods enabled, then switch PayPal on in the subscription settings.
3. Open WooCommerce > Subscription settings and choose the renewal order status.
4. Edit a product and enable the subscription fields on the General tab.

== Changelog ==

= 0.1.0 =
* First version.
