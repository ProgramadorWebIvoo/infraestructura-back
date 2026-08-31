<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\ProjectProposal;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Vista consolidada de histórico de un proveedor: serie mensual de precios
 * cotizados + productos más cotizados + productos personalizados que ofrece
 * (is_custom_origin) sobre product_price_history (mismo log append-only que
 * usa PriceEstimationService), más los proyectos a los que ofertó y cuáles
 * le fueron adjudicados, sobre project_proposals. Alimenta el panel de
 * detalle de proveedor (Fase 3.1.3/3.1.4) — el objetivo declarado es poder
 * reconstruir toda la relación proveedor↔empresa desde una sola pantalla.
 *
 * Deliberadamente NO incluye montos de pago (project_payments): eso queda
 * pendiente de planificar como una fase separada, con su propio control de
 * acceso — un histórico de precios cotizados es información distinta de
 * cuánto se le pagó efectivamente a un proveedor, y esto último amerita
 * una decisión explícita de qué roles pueden verlo antes de exponerlo acá.
 */
class ContractorHistoryService
{
    /**
     * @return array{monthlySeries: array, topProducts: array, customProducts: array, projects: array, stats: array}
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
                'material_catalog.is_custom_origin',
                'product_price_history.price_usd',
                'product_price_history.quoted_at',
            ])
            ->orderBy('product_price_history.quoted_at')
            ->get();

        $projects = $this->buildProjectHistory($contractorCode);

        return [
            'monthlySeries' => $this->buildMonthlySeries($rows, $monthsBack),
            'topProducts' => $this->buildTopProducts($rows),
            'customProducts' => $this->buildCustomProducts($rows),
            'projects' => $projects,
            'stats' => $this->buildStats($contractorCode, $rows, $monthsBack, $projects),
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

    /**
     * Productos "a medida" (material_catalog.is_custom_origin = true) que
     * este proveedor ha cotizado — a diferencia de buildTopProducts() (top 5
     * por volumen, mezcla catálogo + personalizados), esta lista es completa
     * y solo de personalizados: son los que Presidencia todavía no fusionó
     * con un producto de catálogo formal (ver CustomProductResolution), así
     * que ver su evolución de precio acá es la única forma de rastrear si un
     * proveedor está subiendo precios en un producto que aún no tiene
     * histórico "oficial" de catálogo.
     */
    private function buildCustomProducts($rows): array
    {
        $byProduct = [];
        foreach ($rows as $row) {
            if (!$row->is_custom_origin) {
                continue;
            }

            $id = $row->catalog_product_id;
            $byProduct[$id]['name'] ??= $row->product_name;
            $byProduct[$id]['prices'][] = (float) $row->price_usd;
            $byProduct[$id]['dates'][] = $row->quoted_at;
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
                'firstPriceUsd' => $first,
                'lastPriceUsd' => $last,
                'variationPercent' => round($variationPercent, 1),
                'firstQuotedAt' => $data['dates'][0],
                'lastQuotedAt' => end($data['dates']),
            ];
        }

        usort($products, fn ($a, $b) => strtotime($b['lastQuotedAt']) <=> strtotime($a['lastQuotedAt']));

        return $products;
    }

    /**
     * Proyectos a los que este proveedor ofertó (project_proposals.
     * contractor_code — vínculo real por FK, no texto libre como en el
     * flujo de propuestas de materiales del portal) y si esa oferta fue la
     * adjudicada (Project::selected_proposal_id). Incluye ofertas
     * renegociadas (isSuperseded) y retiradas (isWithdrawn, soft-delete) —
     * el objetivo es el historial completo de la relación, no solo las
     * ofertas activas que ya muestra el cuadro comparativo de un proyecto
     * puntual.
     */
    private function buildProjectHistory(string $contractorCode): array
    {
        return ProjectProposal::withTrashed()
            ->where('contractor_code', $contractorCode)
            ->with('project:id,title,type,status,location,created_date,selected_proposal_id')
            ->orderByDesc('fecha_oferta')
            ->get()
            ->filter(fn (ProjectProposal $proposal) => $proposal->project !== null)
            ->map(function (ProjectProposal $proposal) {
                $project = $proposal->project;

                return [
                    'projectId' => $project->id,
                    'projectTitle' => $project->title,
                    'projectType' => $project->type,
                    'projectStatus' => $project->status,
                    'projectLocation' => $project->location,
                    'proposalId' => $proposal->id,
                    'fechaOferta' => $proposal->fecha_oferta?->format('Y-m-d'),
                    'origen' => $proposal->origen,
                    'isAwarded' => $project->selected_proposal_id === $proposal->id,
                    'isSuperseded' => $proposal->replaced_by_id !== null,
                    'isWithdrawn' => $proposal->trashed(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildStats(string $contractorCode, $rows, int $monthsBack, array $projects): array
    {
        $contractor = Contractor::find($contractorCode);
        $totalQuoteCount = $rows->count();
        $distinctProducts = $rows->pluck('catalog_product_id')->unique()->count();
        $customProductCount = $rows->where('is_custom_origin', true)->pluck('catalog_product_id')->unique()->count();
        $awardedProjectCount = count(array_filter($projects, fn ($p) => $p['isAwarded']));
        $totalProjectsBidOn = count(array_unique(array_column($projects, 'projectId')));

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
            'customProductCount' => $customProductCount,
            'totalProjectsBidOn' => $totalProjectsBidOn,
            'awardedProjectCount' => $awardedProjectCount,
            'trendPercent' => $trendPercent,
            'periodMonths' => $monthsBack,
        ];
    }
}
