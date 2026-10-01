<?php

namespace App\Services;

use App\Models\CatalogProductSupplier;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use App\Models\SupplierMaterialProposal;
use App\Models\SupplierMaterialProposalLine;
use Illuminate\Support\Facades\DB;

/**
 * Alimenta el catálogo maestro (`material_catalog`) y el histórico de
 * precios (`product_price_history`) a partir de las líneas normalizadas de
 * una propuesta de proveedor. Se ejecuta una única vez al `submit` de la
 * propuesta (no en cada guardado de borrador), para no contaminar el
 * catálogo con datos a medio llenar.
 *
 * No crea una tabla `suppliers`: `supplier_code` referencia directamente a
 * `contractors.code`, la entidad "proveedor" que ya existe en el sistema.
 */
class CatalogSyncService
{
    public function sync(SupplierMaterialProposal $proposal, array $lines): void
    {
        // Resuelto UNA vez por propuesta, no por línea — antes se repetía
        // la misma query idéntica dentro del foreach (N+1 real).
        $supplierCode = Contractor::codeForSupplierName($proposal->supplier_name);

        // Productos de catálogo ya existentes para los nombres personalizados: UNA consulta para
        // todas las líneas (antes, una búsqueda por línea).
        $customNames = [];
        foreach ($lines as $line) {
            if (!$line->catalog_product_id) {
                $customNames[mb_strtolower(trim($line->custom_product_name ?? ''))] = true;
            }
        }
        $catalogByName = $this->existingCatalogByName(array_keys($customNames));

        $quotedAt = $proposal->submitted_at ?? now();

        DB::transaction(function () use ($proposal, $lines, $supplierCode, &$catalogByName, $quotedAt) {
            $assignments = []; // line id => catalog_product_id (solo líneas que no venían ligadas)
            $supplierStats = []; // catalog_product_id => ['count' => n, 'price' => último precio USD]
            $historyRows = [];

            foreach ($lines as $line) {
                /** @var SupplierMaterialProposalLine $line */
                $catalogProductId = $line->catalog_product_id ?? $this->resolveOrCreateFromCustom($line, $catalogByName);

                if (!$line->catalog_product_id) {
                    $assignments[$line->id] = $catalogProductId;
                    $line->catalog_product_id = $catalogProductId;
                    $line->syncOriginalAttribute('catalog_product_id');
                }

                if (!$supplierCode) {
                    // Proveedor externo sin Contractor registrado todavía (invitación
                    // pública sin alta previa) — no hay a qué CatalogProductSupplier/
                    // ProductPriceHistory vincular. La línea queda normalizada igual;
                    // el catálogo/histórico se completa cuando el proveedor tenga
                    // un CON-xxx (alta manual o automática en otro flujo).
                    continue;
                }

                $supplierStats[$catalogProductId] = [
                    'count' => ($supplierStats[$catalogProductId]['count'] ?? 0) + 1,
                    'price' => $line->unit_price_usd,
                ];

                $historyRows[] = [
                    'catalog_product_id' => $catalogProductId,
                    'supplier_code' => $supplierCode,
                    'quantity' => $line->quantity,
                    'supplier_material_proposal_line_id' => $line->id,
                    'price_usd' => $line->unit_price_usd,
                    'original_currency' => $line->quote_currency,
                    'original_price' => $line->unit_price,
                    'fx_rate_to_usd' => $line->fx_rate_to_usd,
                    'fx_rate_source' => 'BCV',
                    'quoted_at' => $quotedAt->format('Y-m-d H:i:s'),
                    'project_id' => $proposal->project_id,
                    'created_at' => now(),
                ];
            }

            // Escrituras agrupadas (antes, varias consultas por línea).
            $this->assignCatalogProducts($assignments);
            if ($supplierCode) {
                $this->recordSupplierQuotes($supplierCode, $supplierStats, $quotedAt);
            }
            if ($historyRows !== []) {
                ProductPriceHistory::insert($historyRows);
            }
        });
    }

    /**
     * Fija `catalog_product_id` de las líneas personalizadas con UNA sentencia por tanda
     * (CASE), en lugar de un UPDATE por línea.
     *
     * @param  array<int,int>  $assignments  id de línea => id de producto
     */
    private function assignCatalogProducts(array $assignments): void
    {
        $table = (new SupplierMaterialProposalLine)->getTable();

        foreach (array_chunk($assignments, 500, true) as $chunk) {
            $case = 'CASE id';
            $bindings = [];
            foreach ($chunk as $lineId => $productId) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = $lineId;
                $bindings[] = $productId;
            }
            $case .= ' END';

            $ids = array_keys($chunk);
            DB::update(
                "UPDATE {$table} SET catalog_product_id = {$case}, updated_at = ? WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                [...$bindings, now(), ...$ids]
            );
        }
    }

    /**
     * Registra las cotizaciones del proveedor por producto: crea las filas que faltan en un solo
     * INSERT y suma `quote_count` de forma atómica (en SQL, no leyendo y reescribiendo) en las
     * existentes. Equivale al `updateOrCreate` + `increment` por línea de antes: mismo conteo y
     * mismo último precio (el de la última línea de cada producto).
     *
     * @param  array<int,array{count:int,price:float}>  $stats  id de producto => cantidad de líneas y último precio USD
     */
    private function recordSupplierQuotes(string $supplierCode, array $stats, $quotedAt): void
    {
        if ($stats === []) {
            return;
        }

        $existing = CatalogProductSupplier::where('supplier_code', $supplierCode)
            ->whereIn('catalog_product_id', array_keys($stats))
            ->pluck('id', 'catalog_product_id');

        $new = [];
        foreach ($stats as $productId => $stat) {
            if ($existing->has($productId)) {
                CatalogProductSupplier::whereKey($existing[$productId])->update([
                    'last_quoted_at' => $quotedAt,
                    'last_quoted_price_usd' => $stat['price'],
                    'quote_count' => DB::raw('quote_count + ' . (int) $stat['count']),
                ]);
                continue;
            }

            $new[] = [
                'catalog_product_id' => $productId,
                'supplier_code' => $supplierCode,
                'last_quoted_at' => $quotedAt->format('Y-m-d H:i:s'),
                'last_quoted_price_usd' => $stat['price'],
                'quote_count' => $stat['count'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($new !== []) {
            CatalogProductSupplier::insert($new);
        }
    }

    /**
     * Productos de catálogo existentes (no eliminados) por nombre en minúsculas, en una sola consulta.
     *
     * @param  string[]  $lowerNames
     * @return array<string,int> nombre en minúsculas => id
     */
    private function existingCatalogByName(array $lowerNames): array
    {
        $lowerNames = array_values(array_filter($lowerNames, fn ($n) => $n !== ''));
        if ($lowerNames === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($lowerNames, 500) as $chunk) {
            foreach (MaterialCatalog::whereIn(DB::raw('LOWER(name)'), $chunk)->orderBy('id')->get(['id', 'name']) as $product) {
                // Si hay varios con el mismo nombre se conserva el primero, igual que el ->first() anterior.
                $found[mb_strtolower($product->name)] ??= $product->id;
            }
        }

        return $found;
    }

    /**
     * Busca un producto de catálogo existente por nombre exacto/similar; si
     * no hay match, crea uno nuevo marcado `is_custom_origin` para que
     * Presidencia pueda revisarlo/reclasificarlo después (no auto-merge
     * silencioso entre productos personalizados de distintos proveedores).
     */
    private function resolveOrCreateFromCustom(SupplierMaterialProposalLine $line, array &$catalogByName): int
    {
        $name = trim($line->custom_product_name ?? '');
        $key = mb_strtolower($name);

        if ($key !== '' && isset($catalogByName[$key])) {
            return $catalogByName[$key];
        }

        $created = MaterialCatalog::create([
            'name' => $name !== '' ? $name : "Producto sin nombre (línea {$line->id})",
            'unit' => $line->unit !== '' ? $line->unit : 'und',
            'estimated_unit_price' => $line->unit_price_usd,
            'is_active' => true,
            'is_custom_origin' => true,
        ]);

        // Dos líneas con el mismo nombre nuevo comparten el producto recién creado.
        if ($key !== '') {
            $catalogByName[$key] = $created->id;
        }

        return $created->id;
    }
}
