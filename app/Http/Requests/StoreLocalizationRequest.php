<?php

namespace App\Http\Requests;

use App\Models\Localization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (y, con `sometimes`, edición parcial) de una ubicación registrada. */
class StoreLocalizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->route('localization') ? 'sometimes' : 'required';

        return [
            'title'          => [$required, 'string', 'max:255'],
            'city'           => [$required, 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:255'],
            'region'         => ['nullable', 'string', 'max:255'],
            'type'           => [$required, Rule::in(Localization::TYPES)],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'isActive'       => ['sometimes', 'boolean'],
            'residentUserId' => [$required, 'integer', Rule::exists('users', 'id')->where('role', 'RESIDENTE')->where('status', 'Active')],
            'reason'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $current = $this->route('localization');
            $title = $this->input('title', $current?->title);
            $city = $this->input('city', $current?->city);

            if ($title === null || $city === null || $v->errors()->hasAny(['title', 'city'])) {
                return;
            }

            $duplicate = Localization::where('title', $title)->where('city', $city)
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->exists();
            if ($duplicate) {
                $v->errors()->add('title', 'Ya existe una ubicación con ese título en esa ciudad.');
            }
        });
    }
}
