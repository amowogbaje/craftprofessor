<?php

namespace App\Services;

use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every coin movement in the app goes through here so the `wallets.balance`
 * column and the `coin_transactions` ledger can never drift apart — both are
 * always written in the same DB transaction with a row lock on the wallet.
 */
class WalletService
{
    public function balance(User $user): int
    {
        return $user->wallet?->balance ?? 0;
    }

    public function canAfford(User $user, int $coins): bool
    {
        return $this->balance($user) >= $coins;
    }

    /**
     * Debit coins for a generation action. Throws InsufficientCoinsException
     * (before anything is charged) if the user can't cover it.
     */
    public function debit(User $user, int $coins, string $reason, ?Model $reference = null, array $meta = []): CoinTransaction
    {
        if ($coins <= 0) {
            $coins = 0;
        }

        return DB::transaction(function () use ($user, $coins, $reason, $reference, $meta) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrCreate([], ['user_id' => $user->id]);

            if ($wallet->balance < $coins) {
                throw new InsufficientCoinsException($coins, $wallet->balance);
            }

            $wallet->decrement('balance', $coins);
            $wallet->increment('lifetime_spent', $coins);

            $transaction = CoinTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'reason' => $reason,
                'amount' => $coins,
                'balance_after' => $wallet->fresh()->balance,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'meta' => $meta,
            ]);

            Log::info('WalletService: debited coins', [
                'user_id' => $user->id, 'coins' => $coins, 'reason' => $reason, 'balance_after' => $transaction->balance_after,
            ]);

            return $transaction;
        });
    }

    /** Credit coins (purchases, signup bonus, refunds, admin adjustments). */
    public function credit(User $user, int $coins, string $reason, ?string $providerReference = null, array $meta = []): CoinTransaction
    {
        return DB::transaction(function () use ($user, $coins, $reason, $providerReference, $meta) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrCreate([], ['user_id' => $user->id]);

            $wallet->increment('balance', $coins);
            if ($reason === 'purchase') {
                $wallet->increment('lifetime_purchased', $coins);
            }

            $transaction = CoinTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'reason' => $reason,
                'amount' => $coins,
                'balance_after' => $wallet->fresh()->balance,
                'provider_reference' => $providerReference,
                'meta' => $meta,
            ]);

            Log::info('WalletService: credited coins', [
                'user_id' => $user->id, 'coins' => $coins, 'reason' => $reason, 'balance_after' => $transaction->balance_after,
            ]);

            return $transaction;
        });
    }

    /** Refund a previously-debited generation cost (e.g. AI call failed after charging). */
    public function refund(User $user, int $coins, string $reason, ?Model $reference = null, array $meta = []): CoinTransaction
    {
        return $this->credit($user, $coins, 'refund:' . $reason, null, array_merge($meta, [
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
        ]));
    }
}
