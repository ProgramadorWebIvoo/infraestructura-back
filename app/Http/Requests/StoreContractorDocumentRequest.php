<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorDocumentRequest extends FormRequest
{
    public const ALLOWED_MIMES = 'pdf,jpg,jpeg,png';

    public function authorize(): bool
    {
        return true; // ya está detrás de auth:sanctum + role
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('contractor_document_types', 'id')->where('is_active', true)],
            'file' => [
                'required',
                'file',
                'mimes:' . self::ALLOWED_MIMES,
                'max:' . ((int) SettingsService::get('documento_tamano_maximo_mb', 25) * 1024),
            ],
        ];
    }
}
