<?php

namespace App\Http\Requests;

use App\Models\ProjectModificationItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crea o edita una solicitud de modificación de obra (el rol se valida en el servicio: es configurable). */
class StoreProjectModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.materialId' => ['required', 'string', 'max:40'],
            'items.*.type' => ['required', Rule::in([ProjectModificationItem::TYPE_INCREASE, ProjectModificationItem::TYPE_DECREASE])],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
