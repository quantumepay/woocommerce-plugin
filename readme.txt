=== Qoin - Payment Gateway ===
Tags: woocommerce, payments, credit card, quantum epay, qoin
Requires PHP: 7.1
Stable tag: 2.1.0

Accept credit card payments through Quantum ePay's Qoin platform with classic WooCommerce checkout and Checkout Blocks.

== Description ==

Qoin - Payment Gateway connects WooCommerce to Quantum ePay for credit card payments.

Version 2.1.0 includes:

* Classic checkout and WooCommerce Checkout Blocks integrations.
* Merchant-specific live Client ID, Client Secret, and X-TERMINAL-KEY settings.
* Local service fees with fixed or percentage amounts, custom labels, and tax settings.
* WooCommerce refunds and full reversals for payments awaiting settlement.
* Payment review handling for timeouts and unconfirmed results.
* Selected event logging with local storage and remote delivery retries.

WooCommerce must be activated. The plugin declares WooCommerce 3.3 and PHP 7.1 as minimum versions; Checkout Blocks requires a WooCommerce version providing the necessary Blocks and Store API integrations. Use versions of PHP, WordPress, and WooCommerce supported by your site.

HTTPS and a Quantum ePay merchant account are required for live checkout.

= External services =

The plugin connects to Quantum ePay's payment and identity services to authenticate and process payments, lookups, refunds, reversals, and subscription rebilling. Payment requests include the card and billing information needed to process the transaction.

Live services: payments.quantumepay.com and identity.quantumepay.com.
Test services: paymentsuat.quantumepay.com and identityuat.quantumepay.com.

Selected event payloads are sent to qoin-logs.quantumepay.com. Payloads can include the store URL, order and payment identifiers, customer name and email, amount, currency, status, redacted error details, and plugin version. Raw card requests and authorization headers are not intentionally included in event payloads.

The plugin checks GitHub for release information and update packages from quantumepay/woocommerce-plugin.

== Installation ==

1. Obtain woocommerce-gateway-quantum.zip from the appropriate GitHub release.
2. In WordPress, open Plugins > Add New Plugin > Upload Plugin.
3. Upload the ZIP, install it, and activate Qoin - Payment Gateway.
4. Open WooCommerce > Settings > Payments > Quantum ePay.
5. Enable the gateway and configure its title, description, and service fee settings.
6. For Live Mode, save the merchant's X-TERMINAL-KEY, Client ID, and Client Secret.
7. Validate checkout and refund behavior on staging before accepting live payments.

Release downloads: https://github.com/quantumepay/woocommerce-plugin/releases

== Frequently Asked Questions ==

= Can I use credentials from another merchant? =

No. Use credentials issued specifically for this merchant account. Existing installations using older shared credentials need dedicated credentials before using this version in Live Mode.

= Does this support Checkout Blocks? =

Version 2.1.0 includes a Checkout Blocks integration. Use a compatible WooCommerce version and verify the credit card option appears on your checkout.

= How are custom service fees calculated? =

Custom fees apply when Quantum ePay is selected. Fixed fees use the configured amount. Percentage fees use the cart item total after discounts, excluding shipping and taxes.

= Does selecting None disable fees configured in Qoin? =

No. None prevents a custom WooCommerce fee. It does not change the merchant's gateway-side configuration. Same as Gateway also leaves gateway-side fee behavior to Qoin.

= What should I do after a payment timeout? =

Check Qoin before asking the customer to pay again. An unconfirmed result can still represent a processed payment. The affected order is placed on hold pending review.

= Can I issue a partial refund before settlement? =

No. Payments awaiting settlement use a full reversal. Wait for settlement to issue a partial refund.

= Where can I get help? =

Contact support@quantumepay.com. Never send full card details or client secrets in a support request.

== Changelog ==

= 2.1.0 =

* Added WooCommerce Checkout Blocks integration.
* Added local fixed and percentage service fees, custom labels, and tax settings.
* Updated the settings interface and refund dialogs.
* Added merchant-specific live credential configuration and missing-credential notices.
* Improved gateway error handling, payment review handling, and payment/refund locking.
* Added selected event payload logging, payment IDs, local logs, and remote delivery retries.
* Updated GitHub release update handling.

== Upgrade Notice ==

= 2.1.0 =

Live payments require dedicated merchant credentials. Obtain and configure the merchant's Client ID, Client Secret, and X-TERMINAL-KEY before using Live Mode. Validate checkout, fees, and refunds before deployment.
