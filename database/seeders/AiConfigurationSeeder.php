<?php

namespace Database\Seeders;

use App\Models\AiConfiguration;
use Illuminate\Database\Seeder;

/**
 * Proveedor IA por defecto. La API key sale de GEMINI_API_KEY (.env) y se cifra
 * vía el mutator del modelo; sin la variable no se siembra nada (no hay key
 * embebida en el repo).
 */
class AiConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            $this->command?->warn('GEMINI_API_KEY vacío: se omite ai_configurations.');
            return;
        }

        AiConfiguration::updateOrCreate(
            ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite'],
            [
                'api_key' => $apiKey,
                'base_url' => null,
                'max_tokens' => 4096,
                'is_active' => true,
                'is_fallback' => false,
                'sort_order' => 0,
            ]
        );
    }
}
