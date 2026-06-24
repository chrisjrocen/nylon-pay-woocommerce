=== Nylon Pay for WooCommerce ===
Contributors: ocenchris
Tags: woocommerce, payment gateway, mobile money, africa, uganda
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Mobile Money and Bank Transfer payments at WooCommerce checkout via the Nylon Pay payment platform.

== Description ==

Nylon Pay for WooCommerce integrates the Nylon Pay payment platform into your WooCommerce store, allowing customers in Uganda to pay using:

* MTN Mobile Money
* Airtel Money
* Bank Transfer (25+ Ugandan banks)

Payments are initiated at checkout. The customer enters their mobile money phone number and receives a USSD prompt to approve the payment with their PIN. Your WooCommerce order status is updated automatically when the payment succeeds or fails via a secure webhook.

**How it works:**

1. Customer selects "Mobile Money (Nylon Pay)" at checkout and enters their phone number.
2. The plugin calls the Nylon Pay API to initiate the payment.
3. The customer approves the payment on their phone via USSD prompt.
4. Nylon Pay sends a signed webhook to your site confirming the outcome.
5. The WooCommerce order is automatically marked as Processing (success) or Failed.

A cron-based fallback polls the Nylon Pay API hourly for any orders that did not receive a webhook, ensuring no payment is missed.

**Requirements:**

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* HTTPS enabled on your site
* A Nylon Pay merchant account

== Installation ==

1. Upload the `nylon-pay-woocommerce` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce > Settings > Payments** and click on **Nylon Pay**.
4. Enter your **API Key**, **API Secret**, and **Webhook Secret** from your Nylon Pay dashboard.
5. Set your webhook URL in the Nylon Pay dashboard under **Settings > Webhooks**:
   `https://yoursite.com/wp-json/nylon-pay/v1/webhook`
6. Choose **Sandbox** mode for testing or **Live** mode for production, then save.

== Frequently Asked Questions ==

= Where do I get my API keys? =

Log in to your Nylon Pay merchant dashboard and navigate to **Settings > API Keys**, then click **Create Key**. The API Secret is shown only once — copy it immediately and store it securely.

= What is the webhook URL I should enter in the Nylon Pay dashboard? =

`https://yoursite.com/wp-json/nylon-pay/v1/webhook`

Replace `yoursite.com` with your actual domain. The full URL is also displayed on the gateway settings page inside WooCommerce.

= How do I test the plugin before going live? =

Set the plugin to **Sandbox** mode and enter your sandbox API keys (they start with `npk_sandbox_` and `nps_sandbox_`). To receive sandbox webhooks on a local development environment, use a tunnelling tool such as ngrok to expose your local site over HTTPS.

= What currencies are supported? =

UGX (Ugandan Shilling) is the primary currency. The plugin also handles USD, EUR, GBP, KES, TZS, and RWF. Currencies with no subunit (UGX, KES, TZS, RWF) are passed to the API as whole integers; all others are converted to the smallest unit (e.g. cents for USD).

= Where can I find the payment logs? =

Enable **Debug Log** in the gateway settings, then go to **WooCommerce > Status > Logs** and select the `nylon-pay` source. Error-level entries are always written regardless of the debug setting.

= Is HTTPS required? =

Yes. The Nylon Pay API requires a secure HTTPS connection. The plugin will display an admin warning if your site is running in Live mode without HTTPS.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
