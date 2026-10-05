<?php

namespace Extensions\Gateways\Xendit;

use App\Extensions\Foundation\GatewayExtension;
use App\Models\GatewayConfig;
use App\Models\Payment;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Gateway extends GatewayExtension
{
    protected string $id = 'gateway-xendit';

    protected string $name = 'Xendit';

    protected string $description = 'Accept payments in Southeast Asia through Xendit hosted invoices: cards, bank transfers, e-wallets and QR.';

    protected string $type = 'Gateway';

    protected string $gatewayType = 'payment';

    protected array $currencies = ['IDR', 'PHP', 'THB', 'VND', 'MYR', 'USD'];

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'team@wemx.net',
        ],
    ];

    public string $gatewayDescription = 'Pay with cards, bank transfer, e-wallets or QR through Xendit.';

    protected const API_URL = 'https://api.xendit.co';

    public static function setConfig(): array
    {
        return [
            'secret_key' => [
                'label' => 'Secret API key',
                'description' => 'From Xendit Dashboard → Settings → API Keys. Needs write access to Money-in.',
                'type' => 'password',
                'rules' => ['required', 'string', 'starts_with:xnd_'],
            ],
            'callback_token' => [
                'label' => 'Webhook verification token',
                'description' => 'From Xendit Dashboard → Settings → Webhooks. Set the invoice webhook URL to this gateway\'s webhook URL.',
                'type' => 'password',
                'rules' => ['required', 'string'],
            ],
        ];
    }

    public function pay(Payment $payment, GatewayConfig $gatewayConfig): RedirectResponse
    {
        $response = $this->client($gatewayConfig)->post(self::API_URL.'/v2/invoices', array_filter([
            'external_id' => $this->externalId($payment),
            'amount' => $this->amount($payment),
            'currency' => strtoupper((string) $payment->currency),
            'description' => Str::limit((string) ($payment->description ?: 'Payment #'.$payment->id), 250),
            'payer_email' => $payment->user?->email,
            'success_redirect_url' => $payment->callbackUrl(),
            'failure_redirect_url' => $payment->cancelUrl(),
        ], fn ($value) => $value !== null && $value !== ''));

        $invoice = $response->json();

        if ($response->failed() || empty($invoice['invoice_url'])) {
            throw new Exception('Xendit could not create the invoice: '.($invoice['message'] ?? 'HTTP '.$response->status()));
        }

        $payment->update(['transaction_id' => (string) $invoice['id']]);

        return redirect()->away($invoice['invoice_url']);
    }

    /**
     * Xendit sends the customer back here after paying. The invoice is re-read from the API.
     */
    public function callback(Request $request, GatewayConfig $gatewayConfig): RedirectResponse
    {
        $payment = Payment::query()->where('token', $request->query('payment_token'))->first();

        if (! $payment) {
            throw new Exception('Payment not found.');
        }

        if ($payment->isPaid()) {
            return redirect($payment->successUrl());
        }

        if ($payment->transaction_id && $this->completeIfPaid($payment, $this->invoice((string) $payment->transaction_id, $gatewayConfig))) {
            $payment->logPaymentWebhook('Payment completed via Xendit callback');
        }

        return redirect($payment->successUrl());
    }

    /**
     * Invoice webhook. The token is checked, then the invoice is re-read from the API.
     */
    public function webhook(Request $request, GatewayConfig $gatewayConfig): JsonResponse
    {
        if (! hash_equals((string) $gatewayConfig->config('callback_token'), (string) $request->header('x-callback-token'))) {
            return response()->json(['message' => 'Invalid callback token'], 403);
        }

        $externalId = (string) $request->input('external_id');

        if (! str_starts_with($externalId, 'wemx-')) {
            return response()->json(['message' => 'Not a WemX invoice'], 200);
        }

        $payment = Payment::query()->where('token', Str::after($externalId, 'wemx-'))->first();

        if (! $payment) {
            throw new Exception('Payment not found.');
        }

        if ($payment->isPaid()) {
            return response()->json(['message' => 'Payment already completed'], 200);
        }

        $invoice = $this->invoice((string) $request->input('id'), $gatewayConfig);

        if ($this->completeIfPaid($payment, $invoice)) {
            return response()->json(['message' => 'Payment completed'], 200);
        }

        return response()->json(['message' => 'Invoice status '.($invoice['status'] ?? 'unknown')], 200);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function completeIfPaid(Payment $payment, array $invoice): bool
    {
        $status = strtoupper((string) ($invoice['status'] ?? ''));

        if (! in_array($status, ['PAID', 'SETTLED'], true)) {
            return false;
        }

        if (($invoice['external_id'] ?? null) !== $this->externalId($payment)) {
            throw new Exception('Xendit invoice does not belong to this payment.');
        }

        if (strtoupper((string) ($invoice['currency'] ?? $payment->currency)) !== strtoupper((string) $payment->currency)
            || abs((float) ($invoice['amount'] ?? 0) - $this->amount($payment)) > 0.01) {
            throw new Exception('Xendit invoice amount does not match this payment.');
        }

        $payment->completed((string) $invoice['id'], $invoice);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoice(string $invoiceId, GatewayConfig $gatewayConfig): array
    {
        if ($invoiceId === '') {
            throw new Exception('Missing Xendit invoice ID.');
        }

        $response = $this->client($gatewayConfig)->get(self::API_URL.'/v2/invoices/'.rawurlencode($invoiceId));

        if ($response->failed()) {
            throw new Exception('Could not retrieve the invoice from Xendit.');
        }

        return (array) $response->json();
    }

    protected function externalId(Payment $payment): string
    {
        return 'wemx-'.$payment->token;
    }

    /**
     * IDR and VND have no minor units.
     */
    protected function amount(Payment $payment): float
    {
        $decimals = in_array(strtoupper((string) $payment->currency), ['IDR', 'VND'], true) ? 0 : 2;

        return round($payment->total(), $decimals);
    }

    protected function client(GatewayConfig $gatewayConfig): PendingRequest
    {
        return Http::withBasicAuth((string) $gatewayConfig->config('secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
