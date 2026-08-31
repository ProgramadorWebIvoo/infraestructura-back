<?php

namespace App\Services\ExchangeRate;

use Exception;
use Illuminate\Support\Facades\Http;

class DolarVzlaApiFetcher
{
    private const API_URL = 'https://rates.dolarvzla.com/bcv/current.json';
    private const TIMEOUT = 10;

    public function fetch(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get(self::API_URL);

            if (!$response->successful()) {
                throw new Exception("API error: {$response->status()}");
            }

            $data = $response->json();

            return [
                'currencies' => [
                    'USD' => $data['current']['usd'],
                    'EUR' => $data['current']['eur'],
                ],
                'date' => $data['current']['date'],
                'changePercentage' => $data['changePercentage'],
                'source' => 'DOLARVZLA_API',
            ];
        } catch (Exception $e) {
            throw new Exception("DolarVZLA API failed: {$e->getMessage()}");
        }
    }
}
