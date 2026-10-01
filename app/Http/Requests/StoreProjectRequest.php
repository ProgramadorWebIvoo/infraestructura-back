<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsProjectAttachments;
use App\Services\ProjectStateMachine;
use App\Support\ProjectLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    use AcceptsProjectAttachments;

    /** Campo de la petición => tipo de documento. */
    public const ATTACHMENT_FIELDS = ['photos' => 'FOTO', 'documents' => 'CALC', 'plans' => 'PLANO'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:220'],
            'type' => ['required', Rule::exists('project_types', 'key')->where('is_active', true)],
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
        ];
    }

    public function messages(): array
    {
        return $this->attachmentMessages(array_keys(self::ATTACHMENT_FIELDS));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->rejectRepeatedMaterials($v);
            $this->rejectDuplicateProject($v);
        });
    }

    /** El mismo material (catálogo, o nombre + unidad) no puede venir dos veces en la misma obra. */
    private function rejectRepeatedMaterials(Validator $v): void
    {
        $seen = [];
        foreach ((array) $this->input('materials', []) as $index => $item) {
            $key = ! empty($item['materialCatalogId'])
                ? 'cat:' . $item['materialCatalogId']
                : 'txt:' . mb_strtolower(trim((string) ($item['name'] ?? ''))) . '|' . mb_strtolower(trim((string) ($item['unit'] ?? '')));

            if (isset($seen[$key])) {
                $v->errors()->add("materials.$index.name", 'Este material ya está en la lista; ajuste su cantidad en lugar de repetirlo.');
            }
            $seen[$key] = true;
        }
    }

    /** Una obra con el mismo título, tipo y ubicación que otra aún vigente es un duplicado. */
    private function rejectDuplicateProject(Validator $v): void
    {
        $location = ProjectLocation::attributes($this->all())['location'];

        $exists = DB::table('projects')
            ->where('title', trim(strip_tags((string) $this->input('title'))))
            ->where('type', $this->input('type'))
            ->where('location', $location)
            ->where('status', '!=', ProjectStateMachine::STATUSES['COMPLETADO_PAGADO'])
            ->exists();

        if ($exists) {
            $v->errors()->add('title', 'Ya existe una obra vigente con el mismo título, tipo y ubicación.');
        }
    }
}
