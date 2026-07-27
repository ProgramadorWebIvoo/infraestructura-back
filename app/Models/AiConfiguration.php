<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AiConfiguration extends Model
{
    protected $table = 'ai_configurations';

    protected $fillable = [
        'provider',
        'model',
        'api_key',
        'base_url',
        'max_tokens',
        'is_active',
        'is_fallback',
        'sort_order',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'is_fallback' => 'boolean',
        'max_tokens'  => 'integer',
        'sort_order'  => 'integer',
    ];

    // ── Encrypt / decrypt api_key transparently ──

    public function setApiKeyAttribute(string $value): void
    {
        $this->attributes['api_key'] = Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        if ($value === null) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // ── Helpers ──

    /**
     * Serializa a camelCase para la API, mientras que $fillable usa snake_case
     * para la BD (convención Laravel/Eloquent). La diferencia es intencional:
     * la API pública expone camelCase, el modelo interno usa snake_case.
     *
     * Por seguridad NUNCA se envía la API key completa.
     * Solo: `hasApiKey` (bool) + últimos 4 caracteres en `apiKey`.
     *
     * @see $fillable (snake_case) vs esta salida (camelCase)
     */
    public function toArray(): array
    {
        $key = $this->api_key; // triggers accessor → decrypt
        $hasKey = !empty($key);

        return [
            'id'          => $this->id,
            'provider'    => $this->provider,
            'model'       => $this->model,
            'hasApiKey'   => $hasKey,
            'apiKey'      => $hasKey ? '••••' . substr($key, -4) : '',
            'baseUrl'     => $this->base_url,
            'maxTokens'   => $this->max_tokens,
            'isActive'    => $this->is_active,
            'isFallback'  => $this->is_fallback,
            'sortOrder'   => $this->sort_order,
            'createdAt'   => optional($this->created_at)->format('Y-m-d H:i'),
            'updatedAt'   => optional($this->updated_at)->format('Y-m-d H:i'),
        ];
    }
}
