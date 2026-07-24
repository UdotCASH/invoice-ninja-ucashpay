# U.CASH Pay for Invoice Ninja

Accept crypto and card payments on your Invoice Ninja invoices through
[U.CASH Pay](https://pay.u.cash). Customers pay their invoice at the hosted U.CASH Pay
checkout and the invoice is marked **Paid** automatically.

## Set up your pay.u.cash account

You need a free U.CASH Pay account and a store before this plugin can accept payments. Settlement is non-custodial: crypto goes straight to addresses you control.

1. **Sign up** at [pay.u.cash](https://pay.u.cash) with your email and password, then click the verification link in the email U.CASH Pay sends you.
2. **Set your receive addresses.** Go to **Settings → Addresses** and enter a wallet address for each coin you want to accept. You can also use ENS, Unstoppable Domains, or FIO names instead of raw addresses. This is where crypto payments settle.
3. **Create a store.** Go to **Account → Stores**, click **+ Add Store**, name it (for example, after this platform), and create it.
4. **Copy the store credentials.** In that store's row, copy the **Store Cloud Token** and the **Store Webhook Secret**. Use the store-level token, not the account-wide one.
5. **Set the webhook URL.** Paste this plugin's webhook callback URL (shown in the plugin settings below) into the store's **Store Webhook URL** field, save, then click **Test Webhook** to confirm U.CASH Pay can reach your store.

Then paste the **Store Cloud Token** and **Store Webhook Secret** into the plugin configuration fields described below.

> To also accept fiat cards, connect your own Stripe account under **Settings → Payment processors**. Cards run non-custodially through Stripe.

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
   https://YOUR-NINJA/ucashpay/webhook/{company_gateway}
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
