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

        DB::transaction(function () use ($proposal, $lines, $supplierCode) {
            foreach ($lines as $line) {
                /** @var SupplierMaterialProposalLine $line */
                $catalogProductId = $line->catalog_product_id ?? $this->resolveOrCreateFromCustom($line);

                if (!$line->catalog_product_id) {
                    $line->update(['catalog_product_id' => $catalogProductId]);
                }

                if (!$supplierCode) {
                    // Proveedor externo sin Contractor registrado todavía (invitación
                    // pública sin alta previa) — no hay a qué CatalogProductSupplier/
                    // ProductPriceHistory vincular. La línea queda normalizada igual;
                    // el catálogo/histórico se completa cuando el proveedor tenga
                    // un CON-xxx (alta manual o automática en otro flujo).
                    continue;
                }

                CatalogProductSupplier::updateOrCreate(
                    ['catalog_product_id' => $catalogProductId, 'supplier_code' => $supplierCode],
                    [
                        'last_quoted_at' => $proposal->submitted_at ?? now(),
                        'last_quoted_price_usd' => $line->unit_price_usd,
                    ]
                )->increment('quote_count');

                ProductPriceHistory::create([
                    'catalog_product_id' => $catalogProductId,
                    'supplier_code' => $supplierCode,
                    'supplier_material_proposal_line_id' => $line->id,
                    'price_usd' => $line->unit_price_usd,
                    'original_currency' => $line->quote_currency,
                    'original_price' => $line->unit_price,
                    'fx_rate_to_usd' => $line->fx_rate_to_usd,
                    'fx_rate_source' => 'BCV',
                    'quoted_at' => $proposal->submitted_at ?? now(),
                ]);
            }
        });
    }

    /**
     * Busca un producto de catálogo existente por nombre exacto/similar; si
     * no hay match, crea uno nuevo marcado `is_custom_origin` para que
     * Presidencia pueda revisarlo/reclasificarlo después (no auto-merge
     * silencioso entre productos personalizados de distintos proveedores).
     */
    private function resolveOrCreateFromCustom(SupplierMaterialProposalLine $line): int
    {
        $name = trim($line->custom_product_name ?? '');

        $existing = MaterialCatalog::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing->id;
        }

        $created = MaterialCatalog::create([
            'name' => $name !== '' ? $name : "Producto sin nombre (línea {$line->id})",
            'unit' => $line->unit !== '' ? $line->unit : 'und',
            'estimated_unit_price' => $line->unit_price_usd,
            'is_active' => true,
            'is_custom_origin' => true,
        ]);

        return $created->id;
    }
}
