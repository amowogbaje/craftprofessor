<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateJwtSecret extends Command
{
    protected $signature = 'jwt:secret';
    protected $description = 'Generate a random JWT signing secret to put in JWT_SECRET.';

    public function handle(): int
    {
        $this->info('Add this to your .env:');
        $this->line('JWT_SECRET=' . Str::random(64));

        return self::SUCCESS;
    }
}
