<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\PinterestService;
use Illuminate\Console\Command;

class TestPinterestPin extends Command
{
    protected $signature = 'test:pinterest-pin
        {image? : Filename in public/images, required only with --post}
        {--title=Test Pin}
        {--user= : User ID whose connected Pinterest account to use (falls back to .env token)}
        {--post : Also attempt to create a real pin at the end}';

    protected $description = 'Walk through each Pinterest trial scope (user_accounts, boards, pins, ads, catalogs) and report what works';

    public function handle()
    {
        $pinterest = $this->option('user')
            ? PinterestService::forUser((int) $this->option('user'))
            : new PinterestService();

        $account = $this->option('user')
            ? SocialAccount::where('user_id', $this->option('user'))->where('provider', 'pinterest')->first()
            : null;

        if ($account) {
            $this->info("Testing as user #{$account->user_id}, granted scopes: " . implode(', ', $account->scopes ?? []));
        } else {
            $this->info('Testing with static .env token (no per-user scope info available).');
        }

        $this->newLine();
        $this->checkScope('user_accounts:read', fn () => $pinterest->getUserAccount());
        $this->checkScope('boards:read', fn () => $pinterest->listBoards());
        $this->checkScope('pins:read', fn () => $pinterest->listPins());
        $this->checkScope('ads:read', fn () => $pinterest->listAdAccounts());
        $this->checkScope('catalogs:read', fn () => $pinterest->listCatalogs());

        if ($this->option('post')) {
            $this->newLine();
            $this->checkScope('pins:write (create pin)', function () use ($pinterest) {
                $imagePath = 'images/' . $this->argument('image');
                return $pinterest->postLocalImagePin(
                    $this->option('title'),
                    'This is a test description generated from CLI.',
                    'https://medium.com/@amowogbajeflorence/episode-1-whispers-in-the-valley-75f4145060d0',
                    $imagePath
                );
            });
        }

        return self::SUCCESS;
    }

    protected function checkScope(string $label, \Closure $call): void
    {
        $this->line("→ Testing <comment>{$label}</comment>...");

        try {
            $result = $call();
            $summary = is_array($result) ? $this->summarize($result) : $result;
            $this->info("  ✓ OK — {$summary}");
        } catch (\Throwable $e) {
            $this->error("  ✗ FAILED — {$e->getMessage()}");
        }
    }

    protected function summarize(array $result): string
    {
        if (isset($result['items'])) {
            return count($result['items']) . ' item(s) returned';
        }

        return 'response received (' . count($result) . ' top-level keys)';
    }
}