<?php

namespace App\Services\ExchangeRate;

use App\Events\ExchangeRatesUpdated;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync de la tasa USDT, aislado del sync BCV (ExchangeRateSyncService): un
 * fallo acá nunca afecta a USD/EUR y viceversa. A diferencia del BCV, un
 * fallo no notifica al SUPERADMIN — corre cada pocos minutos y una caída
 * del proveedor inundaría la bandeja; queda registrado en los sync logs.
 * Tampoco escribe en el log de auditoría de configuración: `exchange_rates`
 * ya es el histórico inmutable de cada cambio.
 * Si la moneda USDT está inactiva no se consulta la API.
 */
class UsdtRateSyncService
{
    public const CURRENCY_CODE = 'USDT';

    public function __construct(
        private UsdtApiFetcher $fetcher,
        private ExchangeRateSyncLogService $logService,
    ) {}

    /** Nunca lanza (ni siquiera si Pusher o la BD fallan): devuelve false si falló, ya registrado en el log. */
    public function sync(): bool
    {
        if (!Currency::where('code', self::CURRENCY_CODE)->where('is_active', true)->exists()) {
            return true;
        }

        try {
            $this->saveRate($this->fetcher->fetch());
        } catch (Throwable $e) {
            $this->logService->logFailure($e->getMessage(), null, UsdtApiFetcher::SOURCE);
            return false;
        }

        return true;
    }

    /**
     * Solo inserta (y solo deja rastro en el log de sync) si la tasa cambió
     * respecto a la última guardada: el sync corre cada 30 min y repetir la
     * misma cifra inflaría el histórico (`exchange_rates` es inmutable, nunca
     * se limpia) y el panel de logs.
     */
    private function saveRate(array $data): void
    {
        $latest = ExchangeRate::where('currency_code', self::CURRENCY_CODE)->latest('id')->first();

        if ($latest && abs((float) $latest->rate_to_usd - $data['rate']) < 0.000001) {
            return;
        }

        $exchangeRate = ExchangeRate::create([
            'currency_code' => self::CURRENCY_CODE,
            'rate_to_usd' => $data['rate'],
            'source' => $data['source'],
            'effective_at' => min($data['date'], now()),
        ]);

        $this->logService->logSuccess(1, $data['source']);

        // La tasa ya quedó guardada: que el broadcast en tiempo real falle
        // (Pusher caído o mal configurado) no debe convertir el sync en fallo.
        try {
            ExchangeRatesUpdated::dispatch([$exchangeRate->toArray()], $data['source']);
        } catch (Throwable $e) {
            Log::warning("Sync USDT: no se pudo emitir el evento en tiempo real: {$e->getMessage()}");
        }
    }
}
