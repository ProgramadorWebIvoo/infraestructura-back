<?php

namespace App\Services\ExchangeRate;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Tasa USDT/Bs. desde usdt.com.ve (keyless, licencia CC-BY-4.0 — citar la
 * fuente en UI). Toma `data.best.buy_rate`, la mejor tasa de compra entre
 * Binance y Bybit según la propia API. Ignora `data.bcv.rate`: el BCV sale
 * solo de DolarVzlaApiFetcher para no mezclar fuentes.
 */
class UsdtApiFetcher
{
    public const SOURCE = 'USDT_COM_VE';

    private const TIMEOUT = 10;

    /** @return array{rate: float, date: Carbon, source: string} */
    public function fetch(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->get(config('services.usdt_rates.url'));

            if (!$response->successful()) {
                throw new Exception("API error: {$response->status()}");
            }

            $json = $response->json();
            $best = $json['data']['best'] ?? null;
            $rate = (float) ($best['buy_rate'] ?? 0);

            if (!($json['success'] ?? false) || $rate <= 0) {
                throw new Exception('Respuesta sin data.best.buy_rate válido');
            }

            return [
                'rate' => $rate,
                'date' => isset($json['data']['captured_at']) ? Carbon::parse($json['data']['captured_at']) : now(),
                'source' => self::SOURCE . ':' . ($best['source'] ?? 'unknown'),
            ];
        } catch (Exception $e) {
            throw new Exception("USDT API failed: {$e->getMessage()}");
        }
    }
}
