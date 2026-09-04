<?php

namespace App\Console\Commands;

use App\Services\PinterestService;
use Illuminate\Console\Command;

class DeletePinterestPinsByTitle extends Command
{
    protected $signature = 'pinterest:delete-pins-by-title
        {user_id : The ID of the user whose connected Pinterest account owns the pins}
        {title : The pin title to search for}
        {--board= : Restrict the search to a specific board ID (defaults to all boards)}
        {--partial : Match pins whose title *contains* the given text, instead of requiring an exact match}
        {--dry-run : List what would be deleted without actually deleting anything}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Find and delete all Pinterest pins matching a given title, for one user.';

    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        $title = $this->argument('title');
        $boardId = $this->option('board');
        $exactMatch = ! $this->option('partial');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(
            ($dryRun ? '[DRY RUN] ' : '') .
            "Searching pins titled \"{$title}\" for user #{$userId}" .
            ($boardId ? " on board {$boardId}" : ' across all boards') . '...'
        );

        try {
            $service = PinterestService::forUser($userId);
        } catch (\Throwable $e) {
            $this->error("Could not load Pinterest account for user #{$userId}: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Preview the match count before doing anything destructive.
        $preview = $service->findPinsByTitle($title, $boardId, $exactMatch);

        if (empty($preview)) {
            $this->comment('No pins matched that title.');
            return self::SUCCESS;
        }

        $this->info(count($preview) . ' pin(s) matched.');

        if (!$dryRun && !$this->option('force')) {
            if (!$this->confirm('Delete ' . count($preview) . ' pin(s)? This cannot be undone.')) {
                $this->comment('Aborted.');
                return self::SUCCESS;
            }
        }

        try {
            $result = $service->deletePinsByTitle($title, $boardId, $exactMatch, $dryRun);
        } catch (\Throwable $e) {
            $this->error("Failed while deleting pins: {$e->getMessage()}");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Would delete:');
        } else {
            $this->info('Deleted:');
        }
        foreach ($result['deleted'] as $pinId) {
            $this->line(" - {$pinId}");
        }

        if (!empty($result['failed'])) {
            $this->warn('Failed to delete ' . count($result['failed']) . ' pin(s):');
            foreach ($result['failed'] as $pinId => $error) {
                $this->line(" - {$pinId}: {$error}");
            }
        }

        return empty($result['failed']) ? self::SUCCESS : self::FAILURE;
    }
}