<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;

/**
 * Las cantidades de los materiales base del expediente son inmutables desde
 * la carga/renegociación de propuestas: ya pasaron por auditoría de Cierre
 * de Obra, y permitir editarlas ahí rompería esa trazabilidad. El frontend ya
 * bloquea la edición (input de solo lectura para filas no personalizadas),
 * pero esto se re-valida en el servidor porque el payload de materialItems
 * llega como JSON libre — nada impide un request directo a la API con una
 * cantidad alterada.
 *
 * El emparejamiento es por nombre de material (materialName), único vínculo
 * disponible entre un ítem de la propuesta y el material del proyecto — no
 * hay un ID estable en el payload de materialItems.
 */
class ValidatesImmutableMaterialQuantities
{
    public static function validate(Validator $validator, ?Project $project, array $materialItems): void
    {
        if (!$project || empty($materialItems)) {
            return;
        }

        $auditedQuantities = $project->materials()->pluck('quantity', 'name');

        foreach ($materialItems as $index => $item) {
            $name = $item['materialName'] ?? null;
            $quantity = $item['quantity'] ?? null;

            if ($name === null || $quantity === null || !$auditedQuantities->has($name)) {
                continue;
            }

            if ((float) $quantity !== (float) $auditedQuantities->get($name)) {
                $validator->errors()->add(
                    "materialItems.{$index}.quantity",
                    "La cantidad de \"{$name}\" no puede modificarse: ya fue auditada en Cierre de Obra.",
                );
            }
        }
    }
}
