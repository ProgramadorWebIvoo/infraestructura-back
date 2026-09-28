<?php

namespace Tests\Support;

use App\Models\ContractorDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ContractorDocumentFiles
{
    /** Un PDF por cada tipo obligatorio activo; deja el disco local falseado. */
    public static function payload(): array
    {
        Storage::fake('local');

        $files = [];
        foreach (ContractorDocumentType::active()->required()->get() as $type) {
            $files[$type->id] = UploadedFile::fake()->createWithContent("{$type->key}.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        }

        return ['documents' => $files];
    }
}
