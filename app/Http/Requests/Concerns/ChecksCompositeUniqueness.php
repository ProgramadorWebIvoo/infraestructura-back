<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Unicidad sobre una combinación de columnas (p. ej. título + ciudad) con un 422
 * legible, en vez de dejar que el UNIQUE de la BD lance un 500. El UNIQUE sigue
 * siendo la defensa final contra carreras; esto es la capa amable.
 */
trait ChecksCompositeUniqueness
{
    /**
     * @param array<string, mixed> $columns columna => valor (null/'' = no se evalúa)
     * @param mixed $ignoreId id del registro que se edita (se excluye de la búsqueda)
     */
    protected function failIfCombinationExists(
        Validator $validator,
        string $table,
        array $columns,
        string $errorField,
        string $message,
        mixed $ignoreId = null,
        string $idColumn = 'id',
    ): void {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach ($columns as $value) {
            if ($value === null || $value === '') {
                return;
            }
        }

        $query = DB::table($table);
        foreach ($columns as $column => $value) {
            $query->where($column, is_string($value) ? trim($value) : $value);
        }
        if ($ignoreId !== null) {
            $query->where($idColumn, '!=', $ignoreId);
        }

        if ($query->exists()) {
            $validator->errors()->add($errorField, $message);
        }
    }
}
