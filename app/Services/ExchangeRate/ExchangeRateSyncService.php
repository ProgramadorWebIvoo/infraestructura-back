<?php

namespace App\Services\ExchangeRate;

use App\Events\ExchangeRatesUpdated;
use App\Models\AppNotification;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ConfigAuditLog;
use App\Models\User;
use App\Support\NotificationType;
use Illuminate\Support\Facades\DB;
use Exception;

class ExchangeRateSyncService
{
    /** @var array<int, array{source: string, success: bool, duration_ms: int, message: string}> */
    private array $trace = [];

    private bool $debug = false;

    public function __construct(
        private DolarVzlaApiFetcher $dolarVzlaFetcher,
        private BcvScraperFetcher $bcvScraperFetcher,
        private ExchangeRateSyncLogService $logService,
    ) {}

    public function sync(bool $debug = false): bool
    {
        $this->debug = $debug;
        $this->trace = [];

        try {
            $data = $this->tryDolarVzlaApi();
        } catch (Exception $e) {
            try {
                $data = $this->tryBcvScraping();
            } catch (Exception $fallbackError) {
                $this->notifySuperadminFailure($e, $fallbackError);
                return false;
            }
        }

        $this->saveRates($data);

        return true;
    }

    /** Detalle de cada fuente intentada en el último `sync()` — solo poblado si se llamó con `$debug = true`. */
    public function getTrace(): array
    {
        return $this->trace;
    }

    private function tryDolarVzlaApi(): array
    {
        return $this->attempt('DOLARVZLA_API', fn () => $this->dolarVzlaFetcher->fetch());
    }

    private function tryBcvScraping(): array
    {
        return $this->attempt('BCV_SCRAPING', fn () => $this->bcvScraperFetcher->fetch());
    }

    /**
     * Envuelve un fetcher con medición de tiempo y registro en `$trace` —
     * solo cuando `$debug` está activo, para no pagar el costo de
     * `microtime()`/array-building en el camino feliz normal.
     */
    private function attempt(string $source, callable $fetch): array
    {
        if (!$this->debug) {
            return $fetch();
        }

        $start = microtime(true);

        try {
            $data = $fetch();
            $rates = collect($data['currencies'] ?? [])
                ->map(fn ($rate, $code) => "{$code}={$rate}")
                ->implode(', ');

            $this->trace[] = [
                'source' => $source,
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'message' => "Tasas obtenidas: {$rates}",
            ];

            return $data;
        } catch (Exception $e) {
            $this->trace[] = [
                'source' => $source,
                'success' => false,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'message' => $e->getMessage(),
            ];

            throw $e;
        }
    }

    private function saveRates(array $data): void
    {
        DB::transaction(function () use ($data) {
            $savedRates = [];

            foreach ($data['currencies'] as $code => $rate) {
                $currency = Currency::where('code', $code)->firstOrFail();

                $exchangeRate = ExchangeRate::create([
                    'currency_code' => $code,
                    'rate_to_usd' => $rate,
                    'source' => $data['source'],
                    'effective_at' => $data['date'],
                ]);

                $savedRates[] = $exchangeRate->toArray();

                ConfigAuditLog::recordAdminAction(
                    'exchange_rate',
                    'Sync automático de tasa',
                    null,
                    (string) $rate,
                    "{$code}: {$rate} ({$data['source']})"
                );
            }

            $this->logService->logSuccess(count($savedRates), $data['source'], $this->debug ? $this->trace : null);
            ExchangeRatesUpdated::dispatch($savedRates, $data['source']);
        });
    }

    private function notifySuperadminFailure(Exception $primary, Exception $fallback): void
    {
        $errorMsg = "API: {$primary->getMessage()} | Scraping: {$fallback->getMessage()}";

        $superadmins = User::where('role', 'SUPERADMIN')->get();

        foreach ($superadmins as $user) {
            AppNotification::create([
                'user_id' => $user->id,
                'action' => 'Fallo en sync de tasas de cambio',
                'type' => NotificationType::ERROR,
                'details' => "No se pudo obtener tasa BCV ({$errorMsg})",
            ]);
        }

        ConfigAuditLog::recordAdminAction(
            'exchange_rate',
            'Sync automático falló',
            null,
            'ERROR',
            $errorMsg
        );

        $this->logService->logFailure($errorMsg, $this->debug ? $this->trace : null);
    }
}
