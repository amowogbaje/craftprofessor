<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\PinterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan pinterest:refresh-tokens
 * php artisan pinterest:refresh-tokens --buffer=300
 *
 * Proactively refreshes every connected Pinterest SocialAccount whose
 * token expires within --buffer minutes (default 300 = 5 hours, covering
 * the whole overnight posting window with room to spare — see
 * routes/console.php).
 *
 * PinterestService already refreshes a token on-demand mid-request (see
 * ensureFreshToken()/forAccount()), with a tight 5-minute buffer — that
 * stays as a safety net. This command exists so refreshing happens ahead
 * of time instead, on its own schedule, so story:post-pinterest-pin never
 * has to do it inline and never fails with a stale/expired-token 401
 * because of a refresh call that happened to fail right when a pin was
 * about to go out.
 */
class RefreshPinterestTokens extends Command
{
    protected $signature = 'pinterest:refresh-tokens
        {--buffer=300 : Refresh any token expiring within this many minutes}';

    protected $description = 'Proactively refresh Pinterest access tokens that are expired or about to expire.';

    public function handle(): int
    {
        $bufferMinutes = (int) $this->option('buffer');

        $accounts = SocialAccount::where('provider', 'pinterest')
            ->whereNotNull('refresh_token')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No connected Pinterest accounts with a refresh token to check.');
            return self::SUCCESS;
        }

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;
        $cutoff = now()->addMinutes($bufferMinutes);

        foreach ($accounts as $account) {
            // No expiry recorded at all — nothing to compare against, leave alone.
            if (!$account->token_expires_at) {
                $this->line("  user #{$account->user_id}: no token_expires_at on record — skipping.");
                $skipped++;
                continue;
            }

            if ($account->token_expires_at->gt($cutoff)) {
                $this->line("  user #{$account->user_id}: token valid until {$account->token_expires_at->toDateTimeString()} — skipping.");
                $skipped++;
                continue;
            }

            $updated = PinterestService::refreshTokenIfNeeded($account, $bufferMinutes);

            if ($updated->token_expires_at && $updated->token_expires_at->gt($cutoff)) {
                $this->info("  user #{$account->user_id}: refreshed — now valid until {$updated->token_expires_at->toDateTimeString()}.");
                $refreshed++;
            } else {
                $this->error("  user #{$account->user_id}: refresh attempt failed — check the pinterest log channel.");
                Log::channel('pinterest')->error('pinterest:refresh-tokens: refresh attempt did not extend expiry', [
                    'user_id' => $account->user_id,
                    'token_expires_at' => $account->token_expires_at,
                ]);
                $failed++;
            }
        }

        $this->info("Done. Refreshed: {$refreshed}, skipped (still valid): {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
