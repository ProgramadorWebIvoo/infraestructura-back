<?php

namespace App\Services;

use App\Models\Contractor;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Vista consolidada de histórico de un proveedor: serie mensual de precios
 * cotizados + productos más cotizados, sobre product_price_history (mismo
 * log append-only que usa PriceEstimationService). Alimenta el panel de
 * detalle de proveedor (Fase 3.1.3) y, a futuro, el análisis de métrica de
 * aumento por producto de Fase 5.
 */
class ContractorHistoryService
{
    /**
     * @return array{monthlySeries: array, topProducts: array, stats: array}
     */
    public function getSupplierHistory(string $contractorCode, int $monthsBack = 12): array
    {
        $version = CacheVersion::get('contractor_history:' . $contractorCode);
        $cacheKey = "contractor_history:{$contractorCode}:{$monthsBack}:v{$version}";

        return Cache::remember(
            $cacheKey,
            86400, // 24 horas — mismo TTL que PriceEstimationService, el histórico no cambia con frecuencia
            fn () => $this->computeSupplierHistory($contractorCode, $monthsBack)
        );
    }

    private function computeSupplierHistory(string $contractorCode, int $monthsBack): array
    {
        $since = now()->subMonths($monthsBack);

        $rows = DB::table('product_price_history')
            ->join('material_catalog', 'material_catalog.id', '=', 'product_price_history.catalog_product_id')
            ->where('product_price_history.supplier_code', $contractorCode)
            ->where('product_price_history.quoted_at', '>=', $since)
            ->select([
                'product_price_history.catalog_product_id',
                'material_catalog.name as product_name',
                'product_price_history.price_usd',
                'product_price_history.quoted_at',
            ])
            ->orderBy('product_price_history.quoted_at')
            ->get();

        return [
            'monthlySeries' => $this->buildMonthlySeries($rows, $monthsBack),
            'topProducts' => $this->buildTopProducts($rows),
            'stats' => $this->buildStats($contractorCode, $rows, $monthsBack),
        ];
    }

    /**
     * Serie mensual: cantidad de cotizaciones + precio promedio por mes,
     * rellenando con cero los meses sin actividad para que el frontend
     * dibuje una serie continua de $monthsBack puntos.
     */
    private function buildMonthlySeries($rows, int $monthsBack): array
    {
        $byMonth = [];
        foreach ($rows as $row) {
            $month = substr($row->quoted_at, 0, 7); // 'YYYY-MM'
            $byMonth[$month]['sum'] = ($byMonth[$month]['sum'] ?? 0) + (float) $row->price_usd;
            $byMonth[$month]['count'] = ($byMonth[$month]['count'] ?? 0) + 1;
        }

        $series = [];
        for ($i = $monthsBack - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $entry = $byMonth[$month] ?? null;
            $series[] = [
                'month' => $month,
                'quoteCount' => $entry['count'] ?? 0,
                'avgPriceUsd' => $entry ? round($entry['sum'] / $entry['count'], 2) : null,
            ];
        }

        return $series;
    }

    /**
     * Top 5 productos por volumen de cotizaciones, con variación entre la
     * primera y última cotización del período — misma fórmula que
     * PriceHistorySparkline en el frontend (changePercent).
     */
    private function buildTopProducts($rows): array
    {
        $byProduct = [];
        foreach ($rows as $row) {
            $id = $row->catalog_product_id;
            $byProduct[$id]['name'] ??= $row->product_name;
            $byProduct[$id]['prices'][] = (float) $row->price_usd;
        }

        $products = [];
        foreach ($byProduct as $id => $data) {
            $prices = $data['prices'];
            $first = $prices[0];
            $last = end($prices);
            $variationPercent = $first > 0 ? (($last - $first) / $first) * 100 : 0;

            $products[] = [
                'catalogProductId' => $id,
                'productName' => $data['name'],
                'quoteCount' => count($prices),
                'lastPriceUsd' => $last,
                'variationPercent' => round($variationPercent, 1),
            ];
        }

        usort($products, fn ($a, $b) => $b['quoteCount'] <=> $a['quoteCount']);

        return array_slice($products, 0, 5);
    }

    private function buildStats(string $contractorCode, $rows, int $monthsBack): array
    {
        $contractor = Contractor::find($contractorCode);
        $totalQuoteCount = $rows->count();
        $distinctProducts = $rows->pluck('catalog_product_id')->unique()->count();

        // Tendencia general: promedio de precios de la primera mitad del
        // período vs. la segunda mitad (no producto-a-producto, es una señal
        // agregada de "¿este proveedor está subiendo precios en general?").
        $midpoint = now()->subMonths(intdiv($monthsBack, 2));
        $firstHalf = $rows->filter(fn ($r) => $r->quoted_at < $midpoint)->avg('price_usd');
        $secondHalf = $rows->filter(fn ($r) => $r->quoted_at >= $midpoint)->avg('price_usd');
        $trendPercent = ($firstHalf && $secondHalf)
            ? round((($secondHalf - $firstHalf) / $firstHalf) * 100, 1)
            : null;

        return [
            'contractorCode' => $contractorCode,
            'contractorName' => $contractor?->name,
            'rating' => $contractor?->rating,
            'totalQuoteCount' => $totalQuoteCount,
            'distinctProductCount' => $distinctProducts,
            'trendPercent' => $trendPercent,
            'periodMonths' => $monthsBack,
        ];
    }
}
