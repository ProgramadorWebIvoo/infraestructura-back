<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AiConfigurationResource extends JsonResource
{
    /**
     * Reproduce exactamente AiConfiguration::toArray(): la API key nunca se
     * envía completa, solo `hasApiKey` (bool) + últimos 4 caracteres.
     */
    public function toArray($request): array
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
