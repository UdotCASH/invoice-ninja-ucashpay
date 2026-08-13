# U.CASH Pay for Invoice Ninja

Accept crypto and card payments on your Invoice Ninja invoices through
[U.CASH Pay](https://pay.u.cash). Customers pay their invoice at the hosted U.CASH Pay
checkout and the invoice is marked **Paid** automatically.

## How it works

1. The customer clicks **Pay with U.CASH** on the invoice (`processPaymentView`).
2. The button POSTs to the driver's process endpoint -> `processPaymentResponse()` creates a
   U.CASH Pay transaction via the SDK and redirects the buyer to the hosted checkout.
3. After paying, the buyer returns to their invoice list.
4. U.CASH Pay sends an HMAC-signed webhook to the driver's webhook route, registered in
   `boot()`. The driver verifies the signature, reconciles amount + currency, attaches a
   `Payment` to the invoice, and marks the invoice **Paid**.

## Requirements

- Invoice Ninja v5 (self-hosted or hosted).
- PHP 7.2+ with the `curl` extension.
- HTTPS on the public webhook URL.
- A free [U.CASH Pay](https://pay.u.cash) account.

## Install

1. Copy `lib/InvoiceNinja/PaymentDrivers/UcashPayDriver.php` and
   `lib/InvoiceNinja/PaymentDrivers/PayUCashIntegration.php` into your Invoice Ninja install at
   `app/PaymentDrivers/`.
2. Add the driver's class name to your registered gateways (Invoice Ninja -> **Settings ->
   Payment Gateways -> Add Gateway** -> enter `UcashPayDriver`).
3. Run `composer dump-autoload` if you installed via the composer autoload mapping.

## Configure

1. In U.CASH Pay -> **Account -> Stores**, create a store for this Invoice Ninja install and
   copy its **Cloud token** and **Webhook secret**.
2. In Invoice Ninja -> **Settings -> Payment Gateways -> U.CASH Pay**, paste them into
   **Cloud token** and **Webhook secret**, and set the **U.CASH Pay URL** (default
   `https://pay.u.cash`).
3. Set the store's **Webhook URL** in U.CASH Pay to:

   ```
   https://YOUR-NINJA/ucash/webhook/{company_gateway}
   ```

   where `{company_gateway}` is the gateway key shown in the Invoice Ninja gateway config.
4. Save, then send a test invoice.

## Files

- `lib/InvoiceNinja/PaymentDrivers/UcashPayDriver.php` the payment driver class.
- `lib/InvoiceNinja/PaymentDrivers/PayUCashIntegration.php` the shared SDK.
- `composer.json` autoload mapping.

## Multi-store

One U.CASH Pay store per Invoice Ninja install. Running several integrations? Create one store
each in U.CASH Pay -> **Account -> Stores**; each gets its own Cloud token, Webhook secret, and
Webhook URL.

## Support

[https://u.cash](https://u.cash)

(c) 2015-2026 U.CASH. All rights reserved.
