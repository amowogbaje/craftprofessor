<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LogFiveMinuteJob extends Command
{
    protected $signature = 'log:five-minutes';
    protected $description = 'Log a message every five minutes';

    public function handle()
    {
        Log::info('CRON JOB WORKS EVERY FIVE MINUTES');
        $this->info('Message logged successfully.');
    }
}
