<?php

namespace App\Support;

use App\Models\Project;

/**
 * Enlaza cada ítem de una propuesta con el producto de catálogo del material
 * del proyecto (por nombre, el mismo vínculo que usa
 * ValidatesImmutableMaterialQuantities), para que la cotización quede
 * trazada Producto → Proveedor → Precio → Proyecto en product_price_history.
 * Ítems personalizados (sin material homónimo en el proyecto) quedan sin id.
 */
class ProposalMaterialItemsNormalizer
{
    public static function withCatalogIds(Project $project, ?array $items): ?array
    {
        if (empty($items)) {
            return $items;
        }

        $catalogIdsByName = $project->materials()
            ->whereNotNull('material_catalog_id')
            ->pluck('material_catalog_id', 'name');

        return array_map(function (array $item) use ($catalogIdsByName) {
            $name = $item['materialName'] ?? null;

            if (empty($item['catalogProductId']) && $name !== null && $catalogIdsByName->has($name)) {
                $item['catalogProductId'] = (int) $catalogIdsByName->get($name);
            }

            return $item;
        }, $items);
    }
}
