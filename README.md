# Pluxee-Payment-Gateway-for-Woocommerce
Pluxee WooCommerce Gateway adds Pluxee as a payment option in your WooCommerce checkout.

===  Pluxee WooCommerce Gateway ===
Contributors: Pampart Design Software and IT
Tags: woocommerce, payment, pluxee, gateway
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Pluxee as a WooCommerce payment gateway with redirect flow,
webhook verification, and full order status management.

== Description ==

Pluxee WooCommerce Gateway adds Pluxee as a payment option in your WooCommerce
checkout. Key features:

* Redirect-based hosted payment flow.
* Server-side payment verification before any order is marked as paid.
* Webhook / callback handler with signature verification.
* Full WooCommerce refund support (via admin UI or programmatically).
* HPOS (High-Performance Order Storage) compatible.
* Debug logging via WooCommerce built-in logger.
* Test / sandbox mode for safe development.

== Installation ==

1. Upload the `pluxee-payment-gateway` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress → Plugins.
3. Go to WooCommerce → Settings → Payments → Pluxee.
4. Enter your Pluxee credentials (see "Where to Enter API Credentials" below).
5. Copy the Webhook URL shown at the top of the settings page.
6. Register that Webhook URL in your Pluxee merchant dashboard.
7. Enable the gateway and save.

== Where to Enter API Credentials ==

All credentials are entered in:
  WooCommerce → Settings → Payments → Pluxee

| Field            | Description                                         |
|------------------|-----------------------------------------------------|
| Merchant ID      | Your Pluxee merchant identifier                     |
| API Key          | Pluxee public API key                               |
| API Secret       | Pluxee secret key (stored encrypted, never logged)  |
| API Base URL     | Leave empty to use the SDK default                  |
| Webhook Secret   | Shared secret for verifying webhook signatures      |

== Test Flow ==

=== Successful payment ===
1. Enable Test Mode in settings.
2. Add a product to cart and proceed to checkout.
3. Select "Pay with Pluxee" and place order.
4. On the Pluxee hosted page, use the sandbox success card/credentials.
5. After redirect back, the plugin calls verify_payment() against the API.
6. On confirmed PAID status, the order moves to Processing and the cart empties.

=== Failed payment ===
1. Same as above, but use the sandbox declined card/credentials.
2. Pluxee redirects back with a failure indication.
3. The plugin calls verify_payment(); receives FAILED/DECLINED status.
4. Order moves to Failed; customer sees a clean error message.
5. Cart is preserved so the customer can try again.

=== Webhook / callback verification ===
1. Pluxee POSTs to: https://yoursite.com/?wc-api=pluxee_webhook
2. Handler reads raw body and verifies HMAC-SHA256 signature.
3. If valid, handler re-queries the API for current transaction status.
4. Order is updated based on the API-confirmed status (not the payload status).
5. Handler responds 200 OK to acknowledge.
6. Duplicate callbacks for already-paid orders are silently acknowledged.

== Changelog ==

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= Where are the debug logs? =
WooCommerce → Status → Logs → select "pluxee-gateway" from the dropdown.

= Can I use this in a multisite? =
Yes, but each site needs its own credentials configured independently.

= How are refunds handled? =
Via WooCommerce admin: open the order, click "Refund", enter the amount.
The plugin calls the Pluxee API automatically.

= What PHP version is required? =
PHP 8.0 or higher (uses named arguments and readonly properties).

== SDK Integration Notes ==

After installing the plugin, open `includes/class-pluxee-api.php` and locate
every comment block marked:

  ── SDK PLACEHOLDER ──

Replace the stub code in each block with real calls to the Pluxee PHP SDK or
HTTP client, following the Pluxee API documentation.

The four methods to implement are:
  create_payment()     — initiate a payment, return redirect URL
  verify_payment()     — fetch full payment details by transaction ID
  get_payment_status() — lightweight status poll (used by webhook handler)
  refund_payment()     — issue a full or partial refund

Also update get_sdk_client() with SDK instantiation, and confirm the
verify_webhook_signature() algorithm matches the Pluxee specification.
