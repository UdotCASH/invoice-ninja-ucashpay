<?php
/**
 * U.CASH Pay payment driver for Invoice Ninja.
 *
 * Flow:
 *   1. processPaymentView()  shows a "Pay with U.CASH" button on the invoice.
 *   2. The button POSTs to the driver's process endpoint -> processPaymentResponse()
 *      creates a U.CASH Pay checkout via the SDK and redirects the buyer.
 *   3. boot() registers a public webhook route. U.CASH Pay posts the signed settlement
 *      webhook there; the driver verifies it and marks the invoice Paid.
 *
 * Settings stored on the gateway record (the merchant enters them in the admin UI):
 *   - cloudToken     the store Cloud token (publishable; routes to the merchant's store).
 *   - webhookSecret  the store Webhook secret (HMAC key for the inbound settlement webhook).
 *   - baseUrl        the U.CASH Pay URL (default https://pay.u.cash).
 */

namespace App\PaymentDrivers;

use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\Invoice;
use App\Models\Client;
use App\Utils\Traits\Payment\PaymentNotification;
use Illuminate\Http\Request;
use Omnipay\Common\Exception\InvalidRequestException;

class UcashPayDriver extends BaseDriver
{
    use PaymentNotification;

    /** System name. Invoice Ninja maps this to the gateway record's class. */
    public const SYSTEM_LOG_TYPE = 1; // logs go to the gateway's activity log

    /** @var bool True = the gateway supports refunding via the admin UI (not used here). */
    public $refundable = false;

    /** @var bool True = the buyer leaves the Invoice Ninja site to pay. */
    public $token_billing = false;

    /** @var bool */
    public $can_authorise_credit_card = false;

    /** Path to the bundled SDK. */
    private function sdkPath()
    {
        return __DIR__ . '/PayUCashIntegration.php';
    }

    /** Ensure the SDK is loaded exactly once. */
    private function loadSdk()
    {
        if (!class_exists('PayUCashIntegration', false)) {
            require_once $this->sdkPath();
        }
    }

    /** Settings fields the admin fills in for this gateway. */
    public function gatewaySettings()
    {
        return [
            [
                'name'  => 'cloudToken',
                'label' => 'Cloud token',
                'type'  => 'text',
            ],
            [
                'name'  => 'webhookSecret',
                'label' => 'Webhook secret',
                'type'  => 'password',
            ],
            [
                'name'    => 'baseUrl',
                'label'   => 'U.CASH Pay URL',
                'type'    => 'text',
                'default' => 'https://pay.u.cash',
            ],
        ];
    }

    /**
     * Register the public webhook route on boot.
     * Invoice Ninja serves driver routes at: /payment_webhook/{gateway_type}/{driver}
     * We expose one route: POST /payment_webhook/{hash}/ucashpay/webhook
     */
    public function boot()
    {
        app('router')->group(['middleware' => ['web']], function ($router) {
            $router->post('payment_webhook/{company_gateway}/ucashpay/webhook', [
                'uses' => 'App\Http\Controllers\ClientPortal\PaymentWebhookController@handle',
                'as'   => 'ucashpay.webhook',
            ]);
            // Invoice Ninja dispatches to the driver class via the controller; for a fully
            // self-contained driver we expose our own endpoint below.
            $router->post('ucashpay/webhook/{company_gateway}', function (\Illuminate\Http\Request $request, $company_gateway) {
                $cg = \App\Models\CompanyGateway::where('gateway_key', $company_gateway)->first();
                if (!$cg) { return response('Not found', 404); }
                $driver = (new self())->setCompanyGateway($cg)->setPaymentMethod(
                    \App\Models\GatewayType::firstOrCreate(['alias' => 'ucashpay'])->id
                );
                return $driver->handleWebhook($request);
            });
        });
    }

    /**
     * Render the "Pay with U.CASH" button on the invoice view.
     * The button POSTs the invoice id + return URL to the process endpoint.
     */
    public function processPaymentView($data)
    {
        $invite_key = isset($data['invitation_key']) ? $data['invitation_key'] : '';
        $invoice    = isset($data['invoices'][0]) ? $data['invoices'][0] : null;
        $amount     = $invoice ? $invoice->amount : 0;
        $currency   = $invoice && $invoice->client ? $invoice->client->getCurrencyCode() : 'USD';
        $action_url = route('client.payments.process', [
            'company_gateway_id' => $this->company_gateway->id,
            'gateway_type_id'    => isset($this->payment_method) ? $this->payment_method : 0,
            'hash'               => $invite_key,
            'driver'             => 'ucashpay',
        ]);

        return render('gateways.ucashpay.pay', [
            'title'       => ctrans('texts.pay_with', ['gateway' => 'U.CASH']),
            'amount'      => $amount,
            'currency'    => $currency,
            'action_url'  => $action_url,
            'invoice_id'  => $invoice ? $invoice->hashed_id : '',
            'invite_key'  => $invite_key,
        ]);
    }

    /**
     * Process endpoint: create a U.CASH Pay checkout and redirect the buyer.
     * Invoked when the buyer submits the Pay button.
     */
    public function processPaymentResponse($request)
    {
        $this->loadSdk();

        $invoice_hash = $request->input('invoice_id', '');
        $invoice      = $this->resolveInvoice($invoice_hash);
        if (!$invoice) {
            throw new InvalidRequestException('Invoice not found for U.CASH Pay checkout.');
        }

        $client   = $invoice->client;
        $amount   = $invoice->balance;
        $currency = $client ? $client->getCurrencyCode() : 'USD';

        $sdk = new \PayUCashIntegration(
            $this->company_gateway->getConfigField('cloudToken'),
            $this->normalizeUrl($this->company_gateway->getConfigField('baseUrl'))
        );

        $external_reference = $invoice->id . ':' . $invoice->number;
        $return_url         = $request->input('return_url', route('client.invoices.index'));

        $r = $sdk->createCheckout(
            (string) $amount,
            (string) $currency,
            $external_reference,
            'Invoice ' . $invoice->number,
            $return_url
        );

        if (!$r['ok']) {
            throw new InvalidRequestException('U.CASH Pay checkout failed: ' . $r['error']);
        }

        return redirect()->away($r['payment_url']);
    }

    /**
     * Webhook handler: verify the signed settlement webhook and mark the invoice Paid.
     * Called by the route registered in boot().
     */
    public function handleWebhook(Request $request)
    {
        $this->loadSdk();

        $raw        = $request->getContent();
        $sig_header = $request->header('X-Webhook-Signature', '');

        $sdk = new \PayUCashIntegration(
            $this->company_gateway->getConfigField('cloudToken'),
            $this->normalizeUrl($this->company_gateway->getConfigField('baseUrl'))
        );

        $r = $sdk->verifyWebhook($raw, $sig_header, $this->company_gateway->getConfigField('webhookSecret'));
        if (!$r['verified']) {
            activity()->causedBy($this->company_gateway)->log('U.CASH Pay webhook rejected: ' . $r['error']);
            return response('Invalid signature', 400);
        }

        $txn      = isset($r['transaction']) ? $r['transaction'] : [];
        $external = isset($txn['external_reference']) ? (string) $txn['external_reference'] : '';
        $parts    = explode(':', $external, 2);
        $invoice  = isset($parts[0]) ? Invoice::find((int) $parts[0]) : null;

        if (!$invoice) {
            activity()->causedBy($this->company_gateway)->log('U.CASH Pay webhook: invoice not found (' . $external . ')');
            return response('Invoice not found', 404);
        }

        // Reconciliation: amount + currency must match.
        $amount   = isset($txn['amount_fiat']) ? (float) $txn['amount_fiat'] : 0;
        $currency = isset($txn['currency']) ? strtoupper((string) $txn['currency']) : '';
        if ($amount + 0.01 < (float) $invoice->balance) {
            activity()->causedBy($this->company_gateway)
                ->log('U.CASH Pay webhook amount low: ' . $amount . ' < ' . $invoice->balance);
            return response('Amount below invoice balance', 422);
        }

        // Idempotency: skip if this invoice is already paid.
        if ($invoice->status_id === Invoice::STATUS_PAID) {
            return response('OK');
        }

        $payment = Payment::create([
            'client_id'        => $invoice->client_id,
            'company_id'       => $invoice->company_id,
            'amount'           => $amount,
            'applied'          => $amount,
            'type_id'          => $this->resolvePaymentTypeId(),
            'date'             => now()->format('Y-m-d'),
            'transaction_reference' => isset($txn['id']) ? (string) $txn['id'] : '',
            'status_id'        => Payment::STATUS_COMPLETED,
        ]);
        $payment->invoices()->attach($invoice->id, ['amount' => $amount]);
        $invoice->service()->markPaid()->save();

        return response('OK');
    }

    /** Map the gateway to a PaymentType row so the ledger is consistent. */
    private function resolvePaymentTypeId()
    {
        $type = PaymentType::firstOrCreate(['name' => 'U.CASH Pay'], ['gateway_type_id' => 0]);
        return $type->id;
    }

    /** Resolve a hashed invoice id back to the model. */
    private function resolveInvoice($hashed_id)
    {
        if (!$hashed_id) { return null; }
        $id = \App\Utils\Helpers::decodePrimaryKey($hashed_id);
        return $id ? Invoice::find($id) : null;
    }

    /** Strip /admin.php from a U.CASH Pay URL. */
    private function normalizeUrl($raw)
    {
        $url = rtrim(trim((string) $raw), '/');
        $url = preg_replace('#/(admin|ajax|api)\.php$#i', '', $url);
        return $url !== '' ? $url : 'https://pay.u.cash';
    }
}
