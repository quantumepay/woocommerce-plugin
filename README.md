# Qoin — Payment Gateway for WooCommerce

Accept credit card payments through Quantum ePay's Qoin platform using WooCommerce classic checkout or the Checkout Block.

**Current version:** 2.1.0

## Requirements

- WordPress with WooCommerce activated.
- PHP 7.1 or newer, as declared by the plugin. Use a PHP version supported by your installed WordPress and WooCommerce versions.
- WooCommerce 3.3 or newer, as declared by the plugin. Checkout Blocks requires a WooCommerce version that provides the Blocks and Store API integrations used by this plugin.
- HTTPS for checkout.
- A Quantum ePay merchant account and credentials issued for that merchant.

## Installation

1. Download the `woocommerce-gateway-quantum.zip` asset from the appropriate [GitHub release](https://github.com/quantumepay/woocommerce-plugin/releases).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **Qoin - Payment Gateway**.
4. Open **WooCommerce → Settings → Payments → Quantum ePay**.
5. Configure the gateway before accepting payments.

For an existing installation, back up the site and validate the update on staging first.

## Merchant credentials

Live Mode requires all three values:

- X-TERMINAL-KEY
- Client ID
- Client Secret

Use credentials issued specifically for the merchant account. Contact Quantum ePay support if credentials are missing. Do not reuse another merchant's credentials or commit credentials to this repository.

Existing installations using older shared credentials must obtain their dedicated credentials before switching to this version in Live Mode.

Saved client credentials are not displayed back in the settings fields. Leaving those fields blank preserves their existing values; entering new values replaces them.

## Checkout

The plugin includes integrations for classic WooCommerce checkout and the WooCommerce Checkout Block. Customers enter their card number, expiration date, and security code at checkout.

For Blocks, ensure the plugin is enabled and confirm that the credit card option appears in the Checkout Block. Validate both checkout layouts used by the merchant before deployment.

## Service fees

| Setting | Behavior |
| --- | --- |
| Same as Gateway | The plugin does not add a custom WooCommerce fee. Any gateway-side fee depends on the merchant's Qoin configuration. |
| Custom | Adds a local WooCommerce fee when Quantum ePay is selected. Supports a fixed amount or a percentage, a custom label, and a taxable option. |
| None | The plugin does not add a custom WooCommerce fee. This setting does not change Qoin's gateway-side configuration. |

Percentage fees use the cart's item total after discounts, excluding shipping and taxes. Confirm the fee appears, disappears, and recalculates correctly when switching payment methods or updating the cart.

## Refunds

Issue refunds from the WooCommerce order screen using the Quantum ePay refund action. Enter an explicit amount greater than zero and no higher than the remaining refundable balance.

- Settled payments use the gateway refund operation.
- Payments awaiting settlement use a full reversal; partial refunds must wait for settlement.
- If a refund result is uncertain, check the payment in Qoin before retrying.

An original gateway payment ID is required for automatic refunds.

## Payments requiring review

A timeout or an unconfirmed gateway response does not prove that a payment failed. The plugin places affected orders on hold and blocks another attempt against that order pending review.

Check Qoin for the actual payment result before changing the order status or asking the customer to pay again. Notifications go to the store administrator, configured additional recipients, and the Quantum ePay contacts included in the plugin.

## Logging

The plugin records selected payment and error details in WooCommerce logs and sends event payloads to the Quantum ePay logging service. These include order and payment identifiers, amounts, status, and customer contact information where available.

The event payload is built from selected fields rather than the raw card request. Error text is redacted, and raw card numbers, security codes, authorization headers, and client secrets are not intentionally included. Remote events are queued for retry when delivery fails; queue and retry limits apply.

## Release validation

Before updating a merchant, verify successful and declined payments, payment-method switching with custom fees, timeout handling, settled refunds, and reversals. If the merchant uses subscriptions, also validate subscription checkout and scheduled renewals.

## Changelog

### 2.1.0

- Added WooCommerce Checkout Blocks integration.
- Added local service fee configuration with fixed or percentage amounts, custom labels, and tax settings.
- Updated the settings interface and refund dialogs.
- Added merchant-specific live credential configuration and missing-credential notices.
- Improved gateway error handling, payment review handling, and payment/refund locking.
- Added selected event payload logging, payment IDs, local logs, and remote delivery retries.
- Updated GitHub release update handling.

## Development and releases

Repository: https://github.com/quantumepay/woocommerce-plugin

The GitHub Actions release workflow runs when a `v*` tag is pushed and creates the `woocommerce-gateway-quantum.zip` release asset. Keep the plugin header, `WC_QUANTUMEPAY_VERSION`, and the stable tag in `readme.txt` aligned with the release version.

## Support

Contact Quantum ePay support at support@quantumepay.com for merchant credentials and payment assistance. Do not include full card details or secrets in support requests.
