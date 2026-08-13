<?php

namespace App\Models;

use App\Support\AppSettingCatalog;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'min_value',
        'max_value',
    ];

    /**
     * `label`/`description` no son columnas de la tabla — se resuelven desde
     * `AppSettingCatalog` (código, no BD) y se agregan a `toArray()`/JSON
     * automáticamente vía `$appends`, para que el shape de la API no cambie
     * (frontend, tipos y tests siguen recibiendo `label`/`description` como
     * antes).
     */
    protected $appends = ['label', 'description'];

    public function getLabelAttribute(): string
    {
        return AppSettingCatalog::label($this->key);
    }

    public function getDescriptionAttribute(): ?string
    {
        return AppSettingCatalog::description($this->key);
    }

    /**
     * Valor casteado según `type` (string|integer|float|boolean|json).
     * `value` se guarda siempre como texto en BD — el casteo es responsabilidad
     * de este accessor, no de la columna, para permitir tipos mixtos en una
     * sola tabla genérica sin una columna por tipo.
     */
    public function getCastValueAttribute(): string|int|float|bool|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }
}
