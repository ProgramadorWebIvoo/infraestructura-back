<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * API-only SPA: no hay ruta web 'login' a la cual redirigir (causaba
     * RouteNotFoundException -> 500 en vez de 401 cuando el cliente no
     * mandaba Accept: application/json, ver prueba de estrés 2026-09-16).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        return null;
    }
}
