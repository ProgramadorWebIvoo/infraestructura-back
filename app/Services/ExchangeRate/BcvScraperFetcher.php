<?php

namespace App\Services\ExchangeRate;

use Exception;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class BcvScraperFetcher
{
    private const BCV_URL = 'https://www.bcv.org.ve/';
    private const TIMEOUT = 15;

    // TODO: Identificar selectores reales inspeccionando HTML de BCV.org.ve
    // Posibles selectores (a verificar):
    // - .tasas-cambio (contenedor principal)
    // - [data-currency="USD"] (tasa USD)
    // - [data-currency="EUR"] (tasa EUR)
    // - .rate-value (valor de la tasa)
    private const USD_SELECTOR = '[data-currency="USD"] .rate-value';
    private const EUR_SELECTOR = '[data-currency="EUR"] .rate-value';

    public function fetch(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BCV_URL);

            if (!$response->successful()) {
                throw new Exception("BCV page error: {$response->status()}");
            }

            $crawler = new Crawler($response->body());

            $usdRate = $this->extractRate($crawler, self::USD_SELECTOR);
            if ($usdRate === null) {
                throw new Exception("Could not extract USD rate from BCV page");
            }

            $eurRate = $this->extractRate($crawler, self::EUR_SELECTOR);
            if ($eurRate === null) {
                throw new Exception("Could not extract EUR rate from BCV page");
            }

            return [
                'currencies' => [
                    'USD' => $usdRate,
                    'EUR' => $eurRate,
                ],
                'date' => now()->format('Y-m-d'),
                'source' => 'BCV_SCRAPING',
            ];
        } catch (Exception $e) {
            throw new Exception("BCV scraping failed: {$e->getMessage()}");
        }
    }

    /**
     * Extrae una tasa de cambio del HTML usando selector CSS y regex
     * Elimina caracteres no numéricos excepto punto decimal
     */
    private function extractRate(Crawler $crawler, string $selector): ?float
    {
        try {
            $text = $crawler->filter($selector)->text();

            // Limpiar: eliminar espacios, comas de miles, símbolos de moneda
            $cleaned = preg_replace('/[^0-9.]/', '', $text);

            // Si hay múltiples puntos, asumir que el último es el decimal
            if (substr_count($cleaned, '.') > 1) {
                $parts = explode('.', $cleaned);
                $cleaned = implode('', array_slice($parts, 0, -1)) . '.' . end($parts);
            }

            $rate = (float) $cleaned;

            // Validar que la tasa sea positiva y razonable (entre 0.1 y 9999)
            if ($rate <= 0 || $rate > 9999) {
                return null;
            }

            return $rate;
        } catch (Exception) {
            return null;
        }
    }
}
