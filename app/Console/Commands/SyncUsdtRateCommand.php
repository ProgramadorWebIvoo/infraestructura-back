<?php

namespace App\Console\Commands;

use App\Services\ExchangeRate\UsdtRateSyncService;
use Illuminate\Console\Command;

class SyncUsdtRateCommand extends Command
{
    protected $signature = 'sync:usdt-rate';
    protected $description = 'Sincroniza la tasa USDT (usdt.com.ve), independiente del sync BCV';

    public function __construct(
        private UsdtRateSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->syncService->sync()) {
            $this->error('❌ Sync USDT falló (ver exchange_rate_sync_logs)');
            return self::FAILURE;
        }

        $this->info('✅ Sync USDT completado');
        return self::SUCCESS;
    }
}
