<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectHistoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
            'withAlerts' => ['nullable', 'boolean'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
