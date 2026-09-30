<?php

namespace App\Services\ExchangeRate;

use App\Events\ExchangeRatesUpdated;
use App\Models\ConfigAuditLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Exception;

/**
 * Sync de la tasa USDT, aislado del sync BCV (ExchangeRateSyncService): un
 * fallo acá nunca afecta a USD/EUR y viceversa. A diferencia del BCV, un
 * fallo no notifica al SUPERADMIN — corre cada pocos minutos y una caída
 * del proveedor inundaría la bandeja; queda registrado en los sync logs.
 * Si la moneda USDT está inactiva no se consulta la API.
 */
class UsdtRateSyncService
{
    public const CURRENCY_CODE = 'USDT';

    public function __construct(
        private UsdtApiFetcher $fetcher,
        private ExchangeRateSyncLogService $logService,
    ) {}

    /** Nunca lanza: devuelve false si falló (ya registrado en el log). */
    public function sync(): bool
    {
        if (!Currency::where('code', self::CURRENCY_CODE)->where('is_active', true)->exists()) {
            return true;
        }

        try {
            $data = $this->fetcher->fetch();
        } catch (Exception $e) {
            $this->logService->logFailure($e->getMessage(), null, UsdtApiFetcher::SOURCE);
            return false;
        }

        $this->saveRate($data);

        return true;
    }

    /**
     * Solo inserta si la tasa cambió respecto a la última guardada: el sync
     * corre cada 30 min y repetir la misma cifra inflaría el histórico
     * (`exchange_rates` es inmutable, nunca se limpia).
     */
    private function saveRate(array $data): void
    {
        $latest = ExchangeRate::where('currency_code', self::CURRENCY_CODE)->latest('id')->first();

        if ($latest && abs((float) $latest->rate_to_usd - $data['rate']) < 0.000001) {
            $this->logService->logSuccess(0, $data['source']);
            return;
        }

        $exchangeRate = ExchangeRate::create([
            'currency_code' => self::CURRENCY_CODE,
            'rate_to_usd' => $data['rate'],
            'source' => $data['source'],
            'effective_at' => min($data['date'], now()),
        ]);

        ConfigAuditLog::recordAdminAction(
            'exchange_rate',
            'Sync automático de tasa',
            $latest ? (string) $latest->rate_to_usd : null,
            (string) $data['rate'],
            self::CURRENCY_CODE . ": {$data['rate']} ({$data['source']})"
        );

        $this->logService->logSuccess(1, $data['source']);
        ExchangeRatesUpdated::dispatch([$exchangeRate->toArray()], $data['source']);
    }
}
