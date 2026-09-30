<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('FRONTEND_URL_LAN'),
    ]),

    'allowed_origins_patterns' => [],

    // '*' hace que el middleware refleje los encabezados pedidos en el preflight; Idempotency-Key
    // se lista igual de forma explícita para dejar constancia de que el front lo envía.
    'allowed_headers' => ['*', 'Idempotency-Key'],

    // Sin exponerlos, el navegador no deja leer estos encabezados en peticiones cross-origin.
    'exposed_headers' => ['Idempotent-Replayed', 'Retry-After', 'X-Request-ID', 'X-Refresh-Token'],

    'max_age' => 0,

    'supports_credentials' => true,

];
