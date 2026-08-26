<?php

namespace App\Providers;

class RouteServiceProvider
{
    /**
     * La lógica de rutas/rate-limiting de este provider se trasladó a
     * bootstrap/app.php (withRouting) y AppServiceProvider::configureRateLimiting()
     * durante el upgrade a Laravel 12 — esta clase solo sobrevive como
     * contenedor de la constante HOME, usada por RedirectIfAuthenticated.
     */
    public const HOME = '/home';
}
