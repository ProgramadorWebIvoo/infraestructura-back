<?php

return [
    /**
     * Price Estimation — Cálculo de EST (precio estimado)
     * Usa histórico de product_price_history para calcular promedio
     */
    'price_estimation' => [
        'historical_months' => env('PRICE_HISTORICAL_MONTHS', 6),
        'fallback_to_last_quoted' => true,
    ],

    /**
     * Currency — MVP: solo USD por ahora
     * Escalar a multimoneda en Fase X (BCV, Fixer, etc)
     */
    'currency' => [
        'base' => 'USD',
        'local' => env('LOCAL_CURRENCY', 'VES'),
        'supported' => ['USD'], // MVP: solo USD, agregar después
    ],

    /**
     * Variation Alert — Threshold para alertas de precio
     * Variación % > threshold = alerta roja en UI
     */
    'variation_alert' => [
        'threshold_percent' => env('PRICE_VARIATION_THRESHOLD', 25),
        'enable_alerts' => env('PRICE_ALERTS_ENABLED', true),
    ],
];
