<?php

/**
 * Claves de idempotencia de las mutaciones autenticadas (PLAN-Idempotencia).
 */
return [
    /**
     * off     → el middleware no hace nada.
     * log     → aplica replay/409/422 si hay clave; sin clave, solo registra un warning.
     * enforce → sin clave responde 428.
     * (I3 lo leerá de AppSetting para cambiarlo sin redeploy; este es el valor por defecto.)
     */
    'mode' => env('IDEMPOTENCY_MODE', 'off'),

    /** Cuánto se conserva una clave completada; idempotency:prune borra las más viejas. */
    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 72),

    /** Bloqueo mientras la petición original está en proceso; vencido, otra petición puede retomarla. */
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 60),

    /** Bloqueo por prefijo de ruta (las de IA son síncronas y con failover entre proveedores). */
    'lock_seconds_by_path' => [
        'api/ai/*' => 180,
    ],

    /** Tope del cuerpo guardado; por encima se guarda solo el status (replay con `replayed: true`). */
    'max_body_bytes' => (int) env('IDEMPOTENCY_MAX_BODY_BYTES', 256 * 1024),

    /** Segundos sugeridos en `Retry-After` cuando la original sigue en proceso (409). */
    'retry_after_seconds' => 2,

    /**
     * Rutas (patrones de Request::is) que nunca pasan por idempotencia: son naturalmente
     * idempotentes o acciones de prueba/sincronización que deben ejecutarse de verdad.
     */
    'exempt' => [
        'api/logout',
        'api/push-tokens',
        'api/notifications',
        'api/notifications/*',
        'api/ai/config/sync',
        'api/ai/config/*/test',
        'api/system-keys/*/test',
        'api/exchange-rates/sync',
        'api/rating-ia/run',
        'api/debug/*',
    ],
];
