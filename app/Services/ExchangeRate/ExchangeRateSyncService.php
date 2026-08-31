<?php

namespace App\Services\ExchangeRate;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ConfigAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Exception;

class ExchangeRateSyncService
{
    public function __construct(
        private DolarVzlaApiFetcher $dolarVzlaFetcher,
        private BcvScraperFetcher $bcvScraperFetcher,
    ) {}

    public function sync(): void
    {
        try {
            $data = $this->tryDolarVzlaApi();
        } catch (Exception $e) {
            try {
                $data = $this->tryBcvScraping();
            } catch (Exception $fallbackError) {
                $this->notifySuperadminFailure($e, $fallbackError);
                return;
            }
        }

        $this->saveRates($data);
    }

    private function tryDolarVzlaApi(): array
    {
        return $this->dolarVzlaFetcher->fetch();
    }

    private function tryBcvScraping(): array
    {
        return $this->bcvScraperFetcher->fetch();
    }

    private function saveRates(array $data): void
    {
        DB::transaction(function () use ($data) {
            foreach ($data['currencies'] as $code => $rate) {
                $currency = Currency::where('code', $code)->firstOrFail();

                ExchangeRate::create([
                    'currency_code' => $code,
                    'rate_to_usd' => $rate,
                    'source' => $data['source'],
                    'effective_at' => $data['date'],
                ]);

                ConfigAuditLog::recordAdminAction(
                    'exchange_rate',
                    'Sync automático de tasa',
                    null,
                    (string) $rate,
                    "{$code}: {$rate} ({$data['source']})"
                );
            }
        });
    }

    private function notifySuperadminFailure(Exception $primary, Exception $fallback): void
    {
        $superadmins = User::where('role', 'SUPERADMIN')->get();

        foreach ($superadmins as $user) {
            $user->notifications()->create([
                'action' => 'Fallo en sync de tasas de cambio',
                'message' => "No se pudo obtener tasa BCV (API: {$primary->getMessage()}, Scraping: {$fallback->getMessage()})",
                'url' => '/config-app#monedas',
            ]);
        }

        ConfigAuditLog::recordAdminAction(
            'exchange_rate',
            'Sync automático falló',
            null,
            'ERROR',
            "API: {$primary->getMessage()} | Scraping: {$fallback->getMessage()}"
        );
    }
}
