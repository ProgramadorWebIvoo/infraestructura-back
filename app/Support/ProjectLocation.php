<?php

namespace App\Support;

use App\Models\Localization;
use Illuminate\Validation\Rule;

/**
 * Ubicación de una obra (F2-R R7c, D10): exactamente una de dos formas.
 * - `localizationId`: ubicación registrada y activa (hereda su residente).
 * - `location`: texto libre (personalizada; el residente lo elige Auditoría).
 * Con ubicación registrada `projects.location` guarda la copia "título — ciudad" (S9).
 */
class ProjectLocation
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'location'       => ['nullable', 'string', 'max:180', 'required_without:localizationId', 'prohibits:localizationId'],
            'localizationId' => ['nullable', 'integer', Rule::exists('localizations', 'id')->where('is_active', true)],
        ];
    }

    /** Columnas de `projects` derivadas de los datos validados. */
    public static function attributes(array $data): array
    {
        if (! empty($data['localizationId'])) {
            $localization = Localization::findOrFail($data['localizationId']);

            return ['localization_id' => $localization->id, 'location' => $localization->locationLabel(), 'resident_user_id' => null];
        }

        return ['localization_id' => null, 'location' => strip_tags($data['location']), 'resident_user_id' => null];
    }
}
