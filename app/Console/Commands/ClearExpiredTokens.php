<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearExpiredTokens extends Command
{
    protected $signature = 'sanctum:clear-expired-tokens';
    protected $description = 'Delete expired personal access tokens from the database';

    public function handle(): int
    {
        $deleted = DB::table('personal_access_tokens')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$deleted} expired token(s).");

        return self::SUCCESS;
    }
}
