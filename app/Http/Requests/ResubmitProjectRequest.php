<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsProjectAttachments;
use App\Models\ProjectDocument;
use App\Support\ProjectDocumentFileRules;
use App\Support\ProjectLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitProjectRequest extends FormRequest
{
    use AcceptsProjectAttachments;

    /** Campo de la petición => tipo de documento. */
    public const ATTACHMENT_FIELDS = StoreProjectRequest::ATTACHMENT_FIELDS;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:220'],
            'description' => ['required', 'string'],
            ...ProjectLocation::rules(),
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.id' => ['nullable', 'string', 'max:40'],
            'materials.*.materialCatalogId' => ['nullable', 'integer', 'exists:material_catalog,id'],
            'materials.*.name' => ['required', 'string', 'max:180'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0'],
            'materials.*.unit' => ['required', 'string', 'max:80'],
            'materials.*.estimatedUnitPrice' => ['required', 'numeric', 'min:0'],
            'materials.*.condition' => ['required', Rule::in(['NUEVO', 'USADO', 'AMBAS'])],
            'materials.*.warrantyValue' => ['nullable', 'integer', 'min:0', 'required_with:materials.*.warrantyUnit'],
            'materials.*.warrantyUnit' => ['nullable', Rule::in(['DIAS', 'MESES', 'ANOS']), 'required_with:materials.*.warrantyValue'],
            'materials.*.brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'materials.*.model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'materials.*.specifications' => ['sometimes', 'nullable', 'string'],
            'materials.*.observations' => ['sometimes', 'nullable', 'string'],
            'estimatedTotal' => ['nullable', 'numeric', 'min:0'],
            ...$this->attachmentRules(self::ATTACHMENT_FIELDS),
            ...$this->replacementRules(),
        ];
    }

    public function messages(): array
    {
        return $this->attachmentMessages(array_keys(self::ATTACHMENT_FIELDS));
    }

    /**
     * Un grupo por cada reemplazo explícito ("Nueva versión" de una fila):
     * el tipo lo resuelve el servicio desde el documento original.
     *
     * @return array<int, array{type: string, files: array, newVersionOf: int}>
     */
    public function replacementGroups(): array
    {
        $groups = [];
        foreach ((array) $this->input('replacements', []) as $index => $replacement) {
            $file = $this->file("replacements.{$index}.file");
            if ($file !== null) {
                $groups[] = ['type' => '', 'files' => [$file], 'newVersionOf' => (int) $replacement['documentId']];
            }
        }

        return $groups;
    }

    /**
     * Nuevas versiones de documentos ya existentes del proyecto: el archivo
     * se valida con las reglas del tipo del documento que reemplaza (no
     * con un tipo declarado por el cliente).
     */
    private function replacementRules(): array
    {
        $projectId = $this->route('project')?->id;
        $rules = [
            'replacements' => ['sometimes', 'array', 'max:' . ProjectDocumentFileRules::maxFileCount()],
            'replacements.*.documentId' => ['required', 'integer', Rule::exists('project_documents', 'id')->where('project_id', $projectId)],
        ];

        foreach ((array) $this->input('replacements', []) as $index => $replacement) {
            $documentId = is_array($replacement) ? ($replacement['documentId'] ?? null) : null;
            $type = $documentId !== null ? ProjectDocument::where('id', $documentId)->value('document_type') : null;
            $rules["replacements.{$index}.file"] = ProjectDocumentFileRules::fileRules($type);
        }

        return $rules;
    }
}
