<?php

namespace App\Services;

use App\Models\ProductPriceHistory;
use Illuminate\Support\Collection;

/**
 * Estadísticas de histórico de precios por producto — "Histórico de
 * productos" de Fase 4: último precio, mínimo, máximo, promedio, variación
 * y % de cambio sobre product_price_history, filtrable por proveedor,
 * proyecto y rango de fechas. Base de lectura de la vista de inflación de
 * Fase 5.1 (que agrega comparación contra referencia externa — fuera de
 * alcance acá).
 *
 * Deliberadamente separado de PriceEstimationService: ese service resuelve
 * "¿cuál es el precio esperado para esta línea de propuesta?" (EST, con
 * fallback), este resuelve "¿cómo se comportó el precio de este producto en
 * el tiempo?" (reporte/analítica) — ejes distintos, casos de uso distintos.
 */
class ProductPriceStatsService
{
    /**
     * @return array{
     *     productId: int,
     *     lastPriceUsd: float|null,
     *     lastCurrency: string|null,
     *     lastQuotedAt: string|null,
     *     minPriceUsd: float|null,
     *     maxPriceUsd: float|null,
     *     avgPriceUsd: float|null,
     *     variationUsd: float|null,
     *     variationPercent: float|null,
     *     dataPoints: int,
     *     series: array
     * }
     */
    public function getStats(
        int $catalogProductId,
        ?string $supplierCode = null,
        ?string $projectId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $rows = $this->fetchRows($catalogProductId, $supplierCode, $projectId, $from, $to);

        return $this->computeStats($catalogProductId, $rows);
    }

    private function fetchRows(
        int $catalogProductId,
        ?string $supplierCode,
        ?string $projectId,
        ?string $from,
        ?string $to
    ): Collection {
        $query = ProductPriceHistory::where('catalog_product_id', $catalogProductId)
            ->when($supplierCode, fn ($q) => $q->where('supplier_code', $supplierCode))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->where('quoted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('quoted_at', '<=', $to))
            // Desempate por `id` (orden de inserción, tabla append-only):
            // sin esto, filas con el mismo `quoted_at` exacto (común en
            // backfills/imports masivos) pueden volver en orden no
            // determinístico entre requests y hacer flip aleatorio de cuál
            // fila es "última" vs "anterior" para variationPercent.
            ->orderBy('quoted_at')
            ->orderBy('id');

        return $query->get([
            'id', 'supplier_code', 'quantity', 'price_usd', 'original_currency',
            'original_price', 'quoted_at', 'project_id', 'origin',
        ]);
    }

    private function computeStats(int $catalogProductId, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [
                'productId' => $catalogProductId,
                'lastPriceUsd' => null,
                'lastCurrency' => null,
                'lastQuotedAt' => null,
                'minPriceUsd' => null,
                'maxPriceUsd' => null,
                'avgPriceUsd' => null,
                'variationUsd' => null,
                'variationPercent' => null,
                'dataPoints' => 0,
                'series' => [],
            ];
        }

        $prices = $rows->pluck('price_usd');
        $last = $rows->last();
        $previous = $rows->count() > 1 ? $rows[$rows->count() - 2] : null;

        $lastPrice = (float) $last->price_usd;
        $previousPrice = $previous ? (float) $previous->price_usd : null;

        $variationUsd = $previousPrice !== null ? round($lastPrice - $previousPrice, 4) : null;
        $variationPercent = ($previousPrice !== null && $previousPrice > 0)
            ? round((($lastPrice - $previousPrice) / $previousPrice) * 100, 2)
            : null;

        return [
            'productId' => $catalogProductId,
            'lastPriceUsd' => $lastPrice,
            'lastCurrency' => $last->original_currency,
            'lastQuotedAt' => $last->quoted_at?->toIso8601String(),
            'minPriceUsd' => (float) $prices->min(),
            'maxPriceUsd' => (float) $prices->max(),
            'avgPriceUsd' => round((float) $prices->avg(), 4),
            'variationUsd' => $variationUsd,
            'variationPercent' => $variationPercent,
            'dataPoints' => $rows->count(),
            'series' => $rows->map(fn (ProductPriceHistory $row) => [
                'quotedAt' => $row->quoted_at?->toIso8601String(),
                'supplierCode' => $row->supplier_code,
                'quantity' => $row->quantity,
                'priceUsd' => (float) $row->price_usd,
                'originalCurrency' => $row->original_currency,
                'originalPrice' => (float) $row->original_price,
                'projectId' => $row->project_id,
                'origin' => $row->origin,
            ])->values()->all(),
        ];
    }
}
