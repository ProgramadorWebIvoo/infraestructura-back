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
    public function __construct(
        private DolarVzlaApiFetcher $dolarVzlaFetcher,
        private BcvScraperFetcher $bcvScraperFetcher,
        private ExchangeRateSyncLogService $logService,
    ) {}

    public function sync(): bool
    {
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

            $this->logService->logSuccess(count($savedRates), $data['source']);
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

        $this->logService->logFailure($errorMsg);
    }
}
