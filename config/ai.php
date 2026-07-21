<?php

/**
 * Configuración del sistema de Evaluación Inteligente con IA.
 * Define los proveedores, orden de failover y API keys.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Orden de failover entre proveedores
    |--------------------------------------------------------------------------
    |
    | El servicio intentará cada proveedor en este orden.
    | Si un proveedor falla (rate limit, timeout, error), pasa al siguiente.
    |
    | Valores soportados: 'openai', 'gemini', 'anthropic'
    |
    */
    'provider_order' => env('AI_PROVIDER_ORDER', 'openai,gemini,anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Timeout por llamada (segundos)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Proveedor: OpenAI (ChatGPT)
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'enabled' => env('OPENAI_ENABLED', true),
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedor: Google Gemini
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'enabled' => env('GEMINI_ENABLED', true),
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'max_tokens' => env('GEMINI_MAX_TOKENS', 8192),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedor: Anthropic (Claude)
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'enabled' => env('ANTHROPIC_ENABLED', true),
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-opus-20240229'),
        'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
    ],

];
