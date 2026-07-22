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

    /**
     * Returns the API key masked, showing only the last 4 characters.
     * eg. "sk-proj-••••••••••••1234"
     */
    public function getMaskedApiKey(): string
    {
        $key = $this->api_key; // triggers decrypt via accessor
        if (empty($key)) return '••••••••';
        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);
        $prefix = $len > 12 ? substr($key, 0, 7) . '-••••••••••••' : str_repeat('•', $len - 4);
        return $prefix . substr($key, -4);
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

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'provider'    => $this->provider,
            'model'       => $this->model,
            'apiKey'      => $this->getMaskedApiKey(),
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
