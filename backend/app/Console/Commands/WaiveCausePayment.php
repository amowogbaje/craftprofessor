<?php

namespace App\Console\Commands;

use App\Models\Cause;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Dev-only bypass for the Cause creation fee. Run with just a user id to
 * waive that user's most recent pending-payment cause, or pass --cause to
 * target a specific one:
 *
 *   php artisan causes:waive-payment 42
 *   php artisan causes:waive-payment 42 --cause=7
 */
class WaiveCausePayment extends Command
{
    protected $signature = 'causes:waive-payment {user_id} {--cause=}';
    protected $description = 'Waive the creation-fee payment for a Cause, bypassing Flutterwave.';

    public function handle(): int
    {
        $user = User::find($this->argument('user_id'));

        if (!$user) {
            $this->error('No user found with that id.');
            return self::FAILURE;
        }

        $query = Cause::where('owner_id', $user->id);

        if ($causeId = $this->option('cause')) {
            $query->where('id', $causeId);
        } else {
            $query->where('payment_status', 'pending')->latest();
        }

        $cause = $query->first();

        if (!$cause) {
            $this->error('No matching cause found for that user.');
            return self::FAILURE;
        }

        if ($cause->isPaid()) {
            $this->info("Cause #{$cause->id} ({$cause->title}) is already {$cause->payment_status} — nothing to do.");
            return self::SUCCESS;
        }

        $cause->waivePayment("waived via console for user #{$user->id}");

        $this->info("Waived payment for Cause #{$cause->id} ({$cause->title}) — status is now active.");

        return self::SUCCESS;
    }
}
