<?php

namespace App\Console\Commands;

use App\Services\ExchangeRate\ExchangeRateSyncService;
use Illuminate\Console\Command;

class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'sync:exchange-rates';
    protected $description = 'Sincroniza tasas de cambio BCV diariamente (API → Scraping fallback)';

    public function __construct(
        private ExchangeRateSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 Iniciando sync de tasas BCV...');

        try {
            if (!$this->syncService->sync()) {
                $this->error('❌ Sync falló: no se pudo obtener la tasa de ninguna fuente');
                return self::FAILURE;
            }

            $this->info('✅ Sync completado exitosamente');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Sync falló: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
