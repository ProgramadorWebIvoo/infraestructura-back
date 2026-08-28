<?php

namespace App\Services;

use App\DTO\PriceEstimate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PriceEstimationService
{
    /**
     * Calcula EST (precio estimado) de un producto por proveedor.
     * Usa promedio de últimos N meses en product_price_history.
     * Fallback: último precio cotizado (catalog_product_suppliers.last_quoted_price_usd)
     *
     * @return PriceEstimate|null
     */
    public function getEstimatedPrice(
        int $catalogProductId,
        string $supplierCode,
        int $monthsBack = 6
    ): ?PriceEstimate {
        // Clave de caché: identifica unívocamente la estimación
        $cacheKey = "price_estimate:{$catalogProductId}:{$supplierCode}:{$monthsBack}";

        // Caché 24 horas — EST cambia lentamente, no necesita ser inmediato
        return \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            86400, // 24 horas
            fn () => $this->computeEstimatedPrice($catalogProductId, $supplierCode, $monthsBack)
        );
    }

    private function computeEstimatedPrice(
        int $catalogProductId,
        string $supplierCode,
        int $monthsBack = 6
    ): ?PriceEstimate {
        // 1. Intentar promedio histórico
        $historicalData = DB::table('product_price_history')
            ->where('catalog_product_id', $catalogProductId)
            ->where('supplier_code', $supplierCode)
            ->where('quoted_at', '>=', now()->subMonths($monthsBack))
            ->select(DB::raw('AVG(price_usd) as avg_price, COUNT(*) as data_points'))
            ->first();

        if ($historicalData && $historicalData->avg_price) {
            return new PriceEstimate(
                value: (float) $historicalData->avg_price,
                source: 'historical_avg',
                dataPoints: (int) $historicalData->data_points,
                periodMonths: $monthsBack,
                referenceDate: now(),
            );
        }

        // 2. Fallback: último precio cotizado
        if (!config('pricing.price_estimation.fallback_to_last_quoted', true)) {
            return null;
        }

        $lastQuoted = DB::table('catalog_product_suppliers')
            ->where('catalog_product_id', $catalogProductId)
            ->where('supplier_code', $supplierCode)
            ->first();

        if ($lastQuoted) {
            return new PriceEstimate(
                value: (float) $lastQuoted->last_quoted_price_usd,
                source: 'last_quoted',
                dataPoints: 1,
                periodMonths: null,
                referenceDate: $lastQuoted->last_quoted_at,
            );
        }

        // 3. Sin histórico ni referencia
        return null;
    }

    /**
     * Calcula EST para múltiples líneas de propuesta.
     * Optimizado para evitar N+1 queries.
     *
     * @param Collection $proposalLines
     * @return Collection<int, ?PriceEstimate>
     */
    public function estimateLinesForProposal(Collection $proposalLines): Collection
    {
        $monthsBack = config('pricing.price_estimation.historical_months', 6);
        $estimates = [];

        foreach ($proposalLines as $line) {
            $estimates[$line->id] = $this->getEstimatedPrice(
                $line->catalog_product_id,
                $line->proposal->supplier_code,
                $monthsBack
            );
        }

        return collect($estimates);
    }
}
