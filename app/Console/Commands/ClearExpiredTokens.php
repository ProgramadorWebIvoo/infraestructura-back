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
        $expiration = config('sanctum.expiration');

        $deleted = DB::table('personal_access_tokens')
            ->where(function ($q) use ($expiration) {
                // Column-based: expires_at in the past
                $q->where('expires_at', '<', now());

                // Config-based: no expires_at but created_at beyond expiration window
                if ($expiration) {
                    $q->orWhere(function ($q2) use ($expiration) {
                        $q2->whereNull('expires_at')
                           ->where('created_at', '<', now()->subMinutes($expiration));
                    });
                }
            })
            ->delete();

        $this->info("Deleted {$deleted} expired token(s).");

        return self::SUCCESS;
    }
}
