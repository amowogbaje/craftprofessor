<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\PinterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBoardIds extends Command
{
    protected $signature = 'pinterest:sync-board-ids
        {--force : Re-sync accounts that already have a board_id}
        {--user= : Only sync a single user_id}';

    protected $description = 'Backfill board_id on social_accounts for Pinterest accounts connected before board tracking existed.';

    public function handle(): int
    {
        $query = SocialAccount::where('provider', 'pinterest');

        if (!$this->option('force')) {
            $query->whereNull('board_id');
        }

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts need syncing.');
            return self::SUCCESS;
        }

        $this->info("Syncing board_id for {$accounts->count()} account(s)...");

        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $succeeded = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $boardId = PinterestService::forAccount($account)->syncBoardToAccount();

                $this->newLine();
                $this->line("  ✓ user_id={$account->user_id} → board_id={$boardId}");
                $succeeded++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("  ✗ user_id={$account->user_id} failed: {$e->getMessage()}");

                Log::channel('pinterest')->warning('pinterest:sync-board-ids: failed to sync account', [
                    'user_id' => $account->user_id,
                    'error' => $e->getMessage(),
                ]);

                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Succeeded: {$succeeded}, Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}