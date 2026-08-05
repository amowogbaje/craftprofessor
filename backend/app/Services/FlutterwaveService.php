<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wraps the Flutterwave Standard payment flow (v3 API):
 *   1. initialize() -> redirect the user to the returned payment link
 *   2. Flutterwave redirects back to your callback URL and/or fires the
 *      webhook -> verify() confirms the transaction server-side before any
 *      coins are credited. NEVER credit coins purely off the redirect.
 */
class FlutterwaveService
{
    protected string $secretKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.flutterwave.secret_key');
        $this->baseUrl = config('services.flutterwave.base_url');

        if (empty($this->secretKey)) {
            throw new RuntimeException('FLUTTERWAVE_SECRET_KEY is not set.');
        }
    }

    /**
     * @return array{tx_ref: string, payment_link: string}
     */
    public function initialize(User $user, int $amount, string $currency, int $coins, string $redirectUrl): array
    {
        return $this->initializePayment($user, $amount, $currency, "{$coins} coins top-up", [
            'user_id' => $user->id,
            'coins' => $coins,
        ], $redirectUrl, 'coins_');
    }

    /**
     * Generic Flutterwave Standard checkout initializer — the coins top-up
     * above and any other one-off fiat charge (e.g. Cause creation fees,
     * see App\Services\Causes\CausePaymentService) both funnel through
     * this so the tx_ref format / error handling only lives in one place.
     *
     * @return array{tx_ref: string, payment_link: string}
     */
    public function initializePayment(User $user, int $amount, string $currency, string $description, array $meta, string $redirectUrl, string $txRefPrefix = 'pay_'): array
    {
        $txRef = $txRefPrefix . $user->id . '_' . Str::random(12) . '_' . now()->timestamp;

        $response = Http::withToken($this->secretKey)
            ->timeout(30)
            ->post("{$this->baseUrl}/payments", [
                'tx_ref' => $txRef,
                'amount' => $amount,
                'currency' => $currency,
                'redirect_url' => $redirectUrl,
                'customer' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'meta' => array_merge(['user_id' => $user->id], $meta),
                'customizations' => [
                    'title' => config('app.name'),
                    'description' => $description,
                ],
            ]);

        if ($response->failed() || data_get($response->json(), 'status') !== 'success') {
            Log::error('FlutterwaveService: initialize failed', ['body' => $response->body()]);
            throw new RuntimeException('Failed to initialize Flutterwave payment: ' . $response->body());
        }

        return [
            'tx_ref' => $txRef,
            'payment_link' => data_get($response->json(), 'data.link'),
        ];
    }

    /**
     * Verify a transaction by Flutterwave's numeric transaction id (the
     * `transaction_id` query param Flutterwave appends to your redirect_url,
     * and also present in the webhook payload as `data.id`).
     */
    public function verify(string $transactionId): array
    {
        $response = Http::withToken($this->secretKey)
            ->timeout(30)
            ->get("{$this->baseUrl}/transactions/{$transactionId}/verify");

        if ($response->failed()) {
            Log::error('FlutterwaveService: verify failed', ['body' => $response->body()]);
            throw new RuntimeException('Failed to verify Flutterwave transaction: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /** Validates the `verif-hash` header Flutterwave sends on webhook calls. */
    public function isValidWebhookSignature(?string $signatureHeader): bool
    {
        $expected = config('services.flutterwave.secret_hash');

        return !empty($expected) && !empty($signatureHeader) && hash_equals($expected, $signatureHeader);
    }
}
