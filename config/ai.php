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

    /*
    |--------------------------------------------------------------------------
    | Modelos seleccionables por proveedor (UI de configuración de IA)
    |--------------------------------------------------------------------------
    |
    | Fuente única de verdad para el selector de modelos del panel de admin
    | (GET /ai/config/models) — evita duplicar esta lista en el frontend.
    |
    */
    'available_models' => [
        'openai' => ['gpt-5.6-sol', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-5.4-nano', 'gpt-5.6-luna', 'gpt-5.6-terra'],
        'anthropic' => ['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5'],
        'gemini' => ['gemini-3.6-flash', 'gemini-3.1-pro-preview', 'gemini-3.5-flash', 'gemini-3.1-flash-lite'],
    ],

];
