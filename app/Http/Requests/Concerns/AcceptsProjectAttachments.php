<?php

namespace App\Http\Requests\Concerns;

use App\Support\ProjectDocumentFileRules;

/**
 * Para los procesos que reciben sus adjuntos en la misma petición (crear,
 * reenviar, rechazar, reevaluar): así un archivo inválido o rechazado por la
 * pared de seguridad tumba el proceso completo en vez de dejarlo registrado
 * sin el archivo.
 *
 * Cuando hay archivos el cliente manda multipart: el resto de los campos
 * viaja como JSON en `payload` (los arrays anidados como `materials` no se
 * expresan bien en FormData) y acá se vuelca a la petición antes de validar.
 */
trait AcceptsProjectAttachments
{
    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');
        if (!is_string($payload)) {
            return;
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $this->merge($decoded);
        }
    }

    /**
     * @param array<string, string> $fieldTypes campo de la petición => tipo de documento
     */
    protected function attachmentRules(array $fieldTypes): array
    {
        $rules = [];
        foreach ($fieldTypes as $field => $type) {
            $rules[$field] = ['sometimes', 'array', 'max:' . ProjectDocumentFileRules::maxFileCount()];
            $rules["{$field}.*"] = ProjectDocumentFileRules::fileRules($type);
        }

        return $rules;
    }

    /**
     * Grupos listos para ProjectDocumentService (solo los campos con archivos).
     *
     * @param array<string, string> $fieldTypes campo de la petición => tipo de documento
     * @return array<int, array{type: string, files: array, newVersionOf: null}>
     */
    public function attachmentGroups(array $fieldTypes): array
    {
        $groups = [];
        foreach ($fieldTypes as $field => $type) {
            $files = $this->file($field) ?? [];
            if ($files !== []) {
                $groups[] = ['type' => $type, 'files' => $files, 'newVersionOf' => null];
            }
        }

        return $groups;
    }

    /** @param array<int, string> $fields */
    protected function attachmentMessages(array $fields): array
    {
        return array_merge(...array_map(fn (string $f) => ProjectDocumentFileRules::messages($f), $fields));
    }
}
