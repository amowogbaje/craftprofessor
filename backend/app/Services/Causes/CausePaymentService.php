<?php

namespace App\Services\Causes;

use App\Models\Cause;
use App\Models\CoinTransaction;
use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles the one-off $10 (config('causes.creation_fee')) fee a user pays
 * to create a Cause. Kept separate from WalletService/coins — this is a
 * flat fiat charge, not a coin debit — but reuses FlutterwaveService's
 * generic initializePayment()/verify() the same way WalletController does
 * for coin top-ups.
 *
 * A dev can bypass this entirely via `php artisan causes:waive-payment
 * {user}` — see App\Console\Commands\WaiveCausePayment.
 */
class CausePaymentService
{
    public function __construct(protected FlutterwaveService $flutterwave)
    {
    }

    /** @return array{tx_ref: string, payment_link: string} */
    public function initiate(Cause $cause, string $redirectUrl): array
    {
        $result = $this->flutterwave->initializePayment(
            $cause->owner,
            (int) $cause->creation_fee_amount,
            $cause->creation_fee_currency,
            "Cause creation fee — \"{$cause->title}\"",
            ['cause_id' => $cause->id, 'type' => 'cause_creation'],
            $redirectUrl,
            'cause_',
        );

        $cause->update(['payment_reference' => $result['tx_ref']]);

        return $result;
    }

    /** Verifies the transaction server-side and, if successful, marks the cause paid+active. Idempotent. */
    public function verifyAndMarkPaid(string $transactionId): Cause
    {
        $data = $this->flutterwave->verify($transactionId);

        if (($data['status'] ?? null) !== 'successful') {
            throw new RuntimeException('Transaction not successful.');
        }

        $causeId = data_get($data, 'meta.cause_id');
        $cause = Cause::findOrFail($causeId);

        if ($cause->isPaid()) {
            return $cause; // already processed — never double-mark
        }

        // Same idempotency guard the coins flow uses, scoped to cause payments.
        $txRef = $data['tx_ref'] ?? null;
        $alreadyProcessed = CoinTransaction::where('provider_reference', $txRef)
            ->where('reason', 'cause_creation_fee')
            ->exists();

        if ($alreadyProcessed) {
            return $cause;
        }

        $cause->markPaid($txRef);

        CoinTransaction::create([
            'user_id' => $cause->owner_id,
            'type' => 'debit',
            'reason' => 'cause_creation_fee',
            'amount' => 0, // fiat, not coins — logged here purely for a unified audit trail
            'balance_after' => $cause->owner->wallet?->balance ?? 0,
            'reference_type' => Cause::class,
            'reference_id' => $cause->id,
            'provider_reference' => $txRef,
            'meta' => ['amount' => $data['amount'] ?? null, 'currency' => $data['currency'] ?? null],
        ]);

        Log::info('CausePaymentService: cause payment verified', ['cause_id' => $cause->id, 'tx_ref' => $txRef]);

        return $cause;
    }
}
