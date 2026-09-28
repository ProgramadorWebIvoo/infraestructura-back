<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\StoreContractorDocumentRequest;
use App\Models\ContractorDocumentType;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;

/**
 * Reglas de `documents[<document_type_id>]` compartidas por el registro
 * público y el alta interna de proveedores: un archivo por tipo activo, y
 * los tipos obligatorios no pueden faltar.
 */
trait ValidatesContractorDocuments
{
    private ?\Illuminate\Support\Collection $activeDocumentTypes = null;

    private function activeDocumentTypes(): \Illuminate\Support\Collection
    {
        return $this->activeDocumentTypes ??= ContractorDocumentType::active()->get();
    }

    protected function documentRules(): array
    {
        $maxKb = (int) SettingsService::get('documento_tamano_maximo_mb', 25) * 1024;
        $rules = ['documents' => ['nullable', 'array']];

        foreach ($this->activeDocumentTypes() as $type) {
            $rules["documents.{$type->id}"] = [
                $type->is_required ? 'required' : 'nullable',
                'file',
                'mimes:' . StoreContractorDocumentRequest::ALLOWED_MIMES,
                "max:{$maxKb}",
            ];
        }

        return $rules;
    }

    protected function documentAttributes(): array
    {
        return $this->activeDocumentTypes()
            ->mapWithKeys(fn ($type) => ["documents.{$type->id}" => mb_strtolower($type->label)])
            ->all();
    }

    /** @return array<int, UploadedFile> document_type_id => archivo */
    public function documentFiles(): array
    {
        $files = [];
        foreach ($this->activeDocumentTypes() as $type) {
            $file = $this->file("documents.{$type->id}");
            if ($file instanceof UploadedFile) {
                $files[$type->id] = $file;
            }
        }

        return $files;
    }
}
